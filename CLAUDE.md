# OpenWolf

@.wolf/OPENWOLF.md

This project uses OpenWolf for context management. Read and follow .wolf/OPENWOLF.md every session. Check .wolf/cerebrum.md before generating code. Check .wolf/anatomy.md before reading files.


# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

PHP-IMAP is a wrapper for common IMAP communication without requiring the php-imap module. It implements the IMAP protocol completely in PHP, supporting IMAP IDLE operations and OAuth authentication. The php-imap extension can be optionally enabled for edge cases and legacy protocols (pop3).

**Official documentation**: https://www.php-imap.com/

## Development Commands

### Testing
```bash
# Run all tests
composer test
# OR
phpunit

# Tests are located in tests/ directory
# Test configuration: phpunit.xml
```

### Code Quality
```bash
# Run Rector for automated refactoring (targets PHP 8.1)
vendor/bin/rector process

# Run PHPStan for static analysis
vendor/bin/phpstan analyse src --level=3  # Recommended: 1 error remaining
vendor/bin/phpstan analyse src --level=5  # Strict: 57 errors remaining

# PHPStan Status:
# - Level 3 (critical errors): 1 error (96% reduction from original 23)
# - Level 5 (strict analysis): 57 errors (72% reduction from original 202)
# - Most remaining errors are flow analysis issues that can be suppressed

# PHP CS Fixer is available but config is in .php-cs-fixer.cache
# The project follows PHP CS standards
```

### Installation
```bash
# Install dependencies
composer install

# Minimum PHP version: 8.2.0
```

## Architecture Overview

### Core Components

**ClientManager** (`src/ClientManager.php`)
- Entry point for creating and managing IMAP client connections
- Handles configuration merging from `src/config/imap.php`
- Supports multiple account configurations
- Usage: `$cm = new ClientManager('path/to/config/imap.php')`

**Client** (`src/Client.php`)
- Main IMAP client class that manages server connections
- Handles authentication (standard login and OAuth)
- Supports two protocol implementations:
  - `ImapProtocol`: Native PHP implementation (default, no extension required)
  - `LegacyProtocol`: Uses php-imap extension (for legacy protocols like pop3)
- Connection lifecycle: `connect()` → `authenticate()` → operations → `disconnect()`
- Manages folder operations via `getFolders()`, `getFolder()`, `createFolder()`

**Folder** (`src/Folder.php`)
- Represents an IMAP mailbox/folder
- Primary entry point for message queries: `$folder->query()` or `$folder->messages()`
- Supports hierarchical folder structures with children
- Key methods:
  - `query()`: Returns WhereQuery instance for searching messages
  - `idle()`: IMAP IDLE support for push notifications
  - `appendMessage()`: Add messages to folder
  - `examine()`: Get folder status without selecting it

**Message** (`src/Message.php`)
- Represents an email message with lazy-loading support
- Constructor fetches headers by default; body fetched on-demand
- Alternative factory method `Message::make()` for raw data
- Key components:
  - `Header`: Parsed email headers
  - `Structure`: MIME structure and body parts
  - `AttachmentCollection`: Message attachments
  - `FlagCollection`: IMAP flags (seen, deleted, etc.)
- Operations: `move()`, `copy()`, `delete()`, `setFlag()`, `unsetFlag()`

### Query System

**WhereQuery** (`src/Query/WhereQuery.php`)
- Fluent query builder for searching messages
- Supports IMAP search criteria (ALL, SEEN, FROM, SUBJECT, etc.)
- Magic methods allow both `where*()` and direct criteria methods
- Example: `$folder->query()->unseen()->from('user@example.com')->get()`
- Extends base `Query` class with filtering capabilities

**Query** (`src/Query/Query.php`)
- Base class for message queries
- Handles pagination, message fetching, and result collections
- Key methods:
  - `get()`: Execute query and return MessageCollection
  - `paginate()`: Get paginated results
  - `getMessage()`: Fetch specific message by UID/sequence

### Protocol Layer

**Protocol Implementations** (`src/Connection/Protocols/`)
- `AbstractProtocol`: Base protocol class
- `ImapProtocol`: Pure PHP IMAP implementation (no extension needed)
- `LegacyProtocol`: Wrapper for php-imap extension functions
- `ProtocolInterface`: Contract for protocol implementations

All protocols handle:
- Low-level IMAP commands
- Response parsing
- Connection management
- Authentication flows

### Support Classes

**Collections** (`src/Support/`)
- `MessageCollection`: Collection of Message objects
- `FolderCollection`: Collection of Folder objects
- `AttachmentCollection`: Collection of Attachment objects
- `FlagCollection`: Collection of message flags
- All extend Illuminate's Collection for powerful data manipulation

**Masks** (`src/Support/Masks/`)
- `MessageMask`: Default message transformation/presentation layer
- `AttachmentMask`: Default attachment transformation layer
- Customizable via client configuration for alternative representations

**Encoding** (`src/EncodingAliases.php`)
- Maps various character encoding aliases to standard names
- Handles encoding conversion for international email support

### Events System

**HasEvents** (`src/Traits/HasEvents.php`)
- Event dispatching for message, folder, and flag operations
- Events: message.new, message.moved, message.deleted, folder.new, etc.
- Configure event handlers in config under 'events' section

## Key Concepts

### Message Fetching Options
- `FT_UID`: Fetch by UID (default, more stable)
- `FT_PEEK`: Fetch without marking as read
- Controlled via `fetchOptions` parameter and config

### Sequence Types
- `ST_UID`: Use message UIDs (persistent)
- `ST_MSGN`: Use message sequence numbers (changes when messages deleted)
- Default configurable in options.sequence

### UID vs Message Number
- UID: Permanent identifier for a message in a folder
- Message number (msgn): Temporary sequence position (1, 2, 3...)
- Always prefer UID for reliability unless you have specific reasons

### Folder Paths vs Names
- Path: Full hierarchical path (e.g., "INBOX/Sent")
- Name: Simple name without parent folders (e.g., "Sent")
- Delimiter: Separator character (usually "/" or ".")

### OAuth Authentication
Set `authentication` to 'oauth' and provide OAuth token as password.

## Configuration

Default configuration in `src/config/imap.php` includes:
- Account credentials and connection settings
- Protocol selection (imap, legacy-imap, legacy-pop3)
- Encryption options (ssl, tls, starttls, notls)
- Fetch options, sequence types, delimiters
- Event handlers and custom masks
- Common folder paths (sent, trash)

## Common Patterns

### Basic Message Retrieval
```php
$client = new Client($config);
$client->connect();
$folder = $client->getFolder('INBOX');
$messages = $folder->messages()->unseen()->get();
```

### IMAP IDLE (Push Notifications)
```php
$folder->idle(function($message) {
    // Handle new message
}, $timeout);
```

### Message Operations
```php
$message->move('INBOX/Archive');
$message->setFlag('Seen');
$message->delete($expunge = true);
```

## Important Implementation Notes

- The library uses `ClientManager::$config` as a static configuration store
- Protocols are selected based on name: "imap" variants use ImapProtocol, others need php-imap extension
- Message bodies are parsed into `$message->bodies['text']` and `$message->bodies['html']`
- Attachments are automatically detected during body parsing
- Connection state is managed - client reopens folders as needed
- Expunge operations are optional on most destructive operations

## Type Safety Notes

- **Connection Handling**: After `checkConnection()` or `getConnection()`, connection is guaranteed to be `AbstractProtocol`
- **IDLE Operations**: `Folder::idle()` requires `ImapProtocol` (not available in LegacyProtocol)
- **Magic Properties**: Message, Attachment, and other classes use magic `__get()/__set()` extensively
  - Properties are dynamically stored in `$attributes` array
  - PHPDoc `@property` tags document available magic properties
- **UID Types**: `getUid()` returns `array|int|false` depending on parameters
  - `null` parameter: returns array of all UIDs
  - `int` parameter: returns single UID or false on failure
- **Fetch Options**: Use `INF` (float) for "last message" in range fetches
- **Boolean vs Int**:
  - `cert_validation` is stored as bool internally
  - `fetchBody` and `fetchFlags` are bool, not int
- **WhereQuery Values**: The `where()` method accepts mixed values (string, Carbon, int, arrays)
