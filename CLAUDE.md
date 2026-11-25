# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

PHP-IMAP is a wrapper for common IMAP communication without requiring the php-imap module. It implements the IMAP protocol completely in PHP, supporting IMAP IDLE operations and OAuth authentication.

**Official documentation**: https://www.php-imap.com/

## Development Commands

### Testing
```bash
# Run all tests
composer test
# OR
phpunit

# Tests are located in tests/ (tests/Unit/ mirrors src/: Header, AddressListParser/MimeHeader, Attribute, Message)
# Test configuration: phpunit.xml
# Tests must pass with and without ext-imap loaded: never make an expectation depend on an extension
```

### Code Quality
```bash
# Run Rector for automated refactoring (targets PHP 8.1)
vendor/bin/rector process

# Run PHPStan for static analysis (phpstan.neon sets level 3)
vendor/bin/phpstan analyse                # Level 3: no errors
vendor/bin/phpstan analyse src --level=5  # Strict: 5 errors remaining (dead catch/ternary/while, unread $config of Structure)

# PHP CS Fixer (config: .php-cs-fixer.dist.php, risky rules needed)
vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --allow-risky=yes <changed files>
# Only pass the files you changed: on the whole tree it reorders properties and reformats unrelated code
```

### Installation
```bash
# Install dependencies
composer install

# Minimum PHP version: 8.4.28
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
- Uses `ImapProtocol`, the native PHP implementation (no extension required)
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
- All extend Doctrine's `ArrayCollection`

**Masks** (`src/Support/Masks/`)
- `MessageMask`: Default message transformation/presentation layer
- `AttachmentMask`: Default attachment transformation layer
- Customizable via client configuration for alternative representations

**MimeHeader** (`src/Support/MimeHeader.php`)
- `MimeHeader::decode()` decodes RFC 2047 encoded words (any charset, Q and B) and leaves the surrounding text untouched
- Used for subjects, address names and attachment names; `mb_decode_mimeheader()` on a whole value would turn raw 8-bit characters into `?`

**AddressListParser** (`src/Support/AddressListParser.php`)
- Pure PHP RFC 5322 address list parser used by `Header`; its result is pinned to `imap_rfc822_parse_headers()` (c-client) by `tests/Unit/HeaderAddressTest.php`
- Missing hosts become `UNKNOWN`, groups yield a start marker, their members and an empty end marker

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
- Protocol selection (imap)
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
- Only the "imap" protocol variants (imap, imap4, imap4rev1) are supported; others throw `ProtocolNotSupportedException`
- Message bodies are parsed into `$message->bodies['text']` and `$message->bodies['html']`
- Attachments are automatically detected during body parsing
- Connection state is managed - client reopens folders as needed
- Expunge operations are optional on most destructive operations

## Type Safety Notes

- **Connection Handling**: After `checkConnection()` or `getConnection()`, connection is guaranteed to be `AbstractProtocol`
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
- **WhereQuery Values**: The `where()` method accepts mixed values (string, DateTimeInterface, int, arrays)

## Gotchas

### Messages and attributes

- `Message::$attributes` holds more than `Attribute` objects: `uid`, `msgn` and `size` are `int|null`, so the `@var` is `array<string, Attribute|int|null>`.
- `Message::$messageId` is an `Attribute` object, not a string. Call `->first()` (or cast) for thread comparisons and before passing it as a string parameter.
- `Message::get()` has an outer `isset($this->attributes[$name])` check that returns early, so no `case` of the `switch` below it needs its own `isset`.
- `peek()` and flag comparisons: `FlagCollection` returns `null` or a flag string, so compare with `=== null` / `!== null`, never `== null`.
- After `make()` always check the result for `null` before calling methods on it (the `populate()` pattern in `Query.php`).
- `getByUidLower()` means `$id < $uid`, `getByUidLowerThan()` means `$id <= $uid`; the docblock of a new `getByUidXxx()` method must describe exactly that.

### snake_case to camelCase

- Only PHP properties and parameters are camelCase. Config array keys (`validate_cert`, `fetch_body`, ...) stay snake_case; `Client::setAccountConfig()` bridges them with `UnicodeString::camel()`.
- IMAP headers arrive as snake_case (`reply_to`, `in_reply_to`), so `Message::__call()` and `Attachment::__call()` use `UnicodeString::camel()` (magic properties are camelCase, e.g. `imgSrc`), not `->snake()`.
- `AttachmentMask` reads `$this->parent->contentType` directly and not through getters; keep it in step when renaming properties.
- PhpStorm's rename refactoring also renames string literals in `ClientManager::get()` calls: check config keys in string arguments by hand afterwards.

### Headers

- `Header` never calls `ext-imap` or `ext-mailparse`: encoded words go through `MimeHeader::decode()` (or `iconv_mime_decode()` for decoder `iconv`), addresses through `AddressListParser`. Results must not depend on loaded extensions.
- `Address::$host` is `UNKNOWN` for a mailbox without host, and a group yields marker entries (start: only `mailbox`, end: empty), exactly like c-client; `HeaderAddressTest` pins this.
- Raw bytes in headers (not encoded words) are kept as they are; do not run them through `mb_decode_mimeheader()`.
- PHP below 8.4.28 is not supported: its `mb_decode_mimeheader()` keeps the whitespace between adjacent encoded words.

### Static analysis

- On the protocol files PHPStan cannot narrow the return type of `fetch()` (`array<int, string|array<string,mixed>>|string`), so `flags()` and `sizes()` use `array<int, mixed>`.
- `array_search()` returns `int<0,max>|false`, never `-1`. `mb_convert_encoding()` never returns `false` according to the current stubs. `iterable<int>` is not a subtype of `array`: for PHP-declared `array|false` returns use `array<int>`.
- `sendRequest()` tokens need `(string)` for ints (`list<string|array{string,string}>`); `setUidCache()` from `fetch()` needs `array_values(array_map('intval', $fetched))` for `list<ImapUid>`.
- A `@phpstan-ignore` that no longer matches becomes an error itself (`ignore.unmatchedIdentifier`): recheck the existing ignores after every fix. `Event::dispatch()` uses `new static()`, for which PHPStan reports `new.static` on abstract classes; that
  line keeps `// @phpstan-ignore new.static (intentional: subclasses are always concrete)`.
- Intelephense P1131 on `@return $this` is a known Intelephense limitation, not a PHPStan error; leave it.
