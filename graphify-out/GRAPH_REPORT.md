# Graph Report - .  (2026-06-22)

## Corpus Check
- Corpus is ~37,304 words - fits in a single context window. You may not need a graph.

## Summary
- 768 nodes · 1326 edges · 32 communities (21 shown, 11 thin omitted)
- Extraction: 97% EXTRACTED · 3% INFERRED · 0% AMBIGUOUS · INFERRED: 38 edges (avg confidence: 0.79)
- Token cost: 0 input · 0 output

## Community Hubs (Navigation)
- [[_COMMUNITY_Message & Collections|Message & Collections]]
- [[_COMMUNITY_Query Engine|Query Engine]]
- [[_COMMUNITY_IMAP Protocol|IMAP Protocol]]
- [[_COMMUNITY_Legacy Protocol|Legacy Protocol]]
- [[_COMMUNITY_WhereQuery Builder|WhereQuery Builder]]
- [[_COMMUNITY_Composer & Dependencies|Composer & Dependencies]]
- [[_COMMUNITY_Protocol Interface|Protocol Interface]]
- [[_COMMUNITY_Client Core|Client Core]]
- [[_COMMUNITY_Exception Hierarchy|Exception Hierarchy]]
- [[_COMMUNITY_DateParser & Attribute|DateParser & Attribute]]
- [[_COMMUNITY_ClientManager|ClientManager]]
- [[_COMMUNITY_Folder & AbstractProtocol|Folder & AbstractProtocol]]
- [[_COMMUNITY_Docs & Changelog|Docs & Changelog]]
- [[_COMMUNITY_Mask Examples|Mask Examples]]
- [[_COMMUNITY_Event System|Event System]]
- [[_COMMUNITY_Attribute & ArrayAccess|Attribute & ArrayAccess]]
- [[_COMMUNITY_AbstractProtocol Layer|AbstractProtocol Layer]]
- [[_COMMUNITY_Attachment|Attachment]]
- [[_COMMUNITY_Structure & Header|Structure & Header]]
- [[_COMMUNITY_Address|Address]]
- [[_COMMUNITY_HasEvents Trait|HasEvents Trait]]
- [[_COMMUNITY_Event Dispatch|Event Dispatch]]
- [[_COMMUNITY_Funding|Funding]]
- [[_COMMUNITY_IMAP Facade|IMAP Facade]]
- [[_COMMUNITY_OAuth & DateTime|OAuth & DateTime]]
- [[_COMMUNITY_Code of Conduct|Code of Conduct]]
- [[_COMMUNITY_Jekyll Config|Jekyll Config]]

## God Nodes (most connected - your core abstractions)
1. `Message` - 80 edges
2. `Query` - 70 edges
3. `ImapProtocol` - 61 edges
4. `LegacyProtocol` - 47 edges
5. `WhereQuery` - 43 edges
6. `Exception` - 41 edges
7. `Client` - 38 edges
8. `self` - 36 edges
9. `ClientManager` - 35 edges
10. `Folder` - 27 edges

## Surprising Connections (you probably didn't know these)
- `Travis CI Configuration` --conceptually_related_to--> `PHP-IMAP README`  [INFERRED]
  .travis.yml → README.md
- `PHP 8.0 Support Added (v4.0.0)` --conceptually_related_to--> `Claude Code Project Guide (CLAUDE.md)`  [INFERRED]
  CHANGELOG.md → CLAUDE.md
- `OAuth Authentication for IMAP` --references--> `PHP-IMAP README`  [INFERRED]
  CLAUDE.md → README.md
- `PHP-IMAP README` --references--> `PHP-IMAP Changelog`  [EXTRACTED]
  README.md → CHANGELOG.md
- `PHP-IMAP README` --references--> `MIT License`  [EXTRACTED]
  README.md → LICENSE.md

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **IMAP Protocol Layer: AbstractProtocol, ImapProtocol, LegacyProtocol, ProtocolInterface** — claude_abstractprotocol, claude_imapprotocol, claude_legacyprotocol, claude_protocolinterface [EXTRACTED 1.00]
- **Query System: Folder -> WhereQuery -> Query -> MessageCollection** — claude_folder, claude_wherequery, claude_query_base, claude_collections [EXTRACTED 1.00]
- **Community Governance: Code of Conduct, Issue Templates, Funding** — code_of_conduct, bug_report_template, feature_request_template, general_help_template [INFERRED 0.85]

## Communities (32 total, 11 thin omitted)

### Community 0 - "Message & Collections"
Cohesion: 0.05
Nodes (10): AttachmentCollection, FlagCollection, Message, Client, Folder, Header, MessageCollection, Part (+2 more)

### Community 1 - "Query Engine"
Cohesion: 0.05
Nodes (12): Query, ArrayCollection, Client, DateTimeImmutable, DateTimeInterface, Message, MessageCollection, self (+4 more)

### Community 3 - "Legacy Protocol"
Cohesion: 0.05
Nodes (3): Connection, LegacyProtocol, Encryption

### Community 4 - "WhereQuery Builder"
Cohesion: 0.12
Nodes (3): Closure, WhereQuery, self

### Community 5 - "Composer & Dependencies"
Cohesion: 0.04
Nodes (44): kylekatarnls/update-helper, authors, autoload, autoload-dev, psr-4, psr-4, dev-master, config (+36 more)

### Community 7 - "Client Core"
Cohesion: 0.11
Nodes (6): Client, AbstractProtocol, Folder, FolderCollection, LoggerInterface, self

### Community 8 - "Exception Hierarchy"
Cohesion: 0.08
Nodes (20): AuthFailedException, ConnectionFailedException, EventNotFoundException, FolderFetchingException, GetMessagesFailedException, InvalidMessageDateException, InvalidWhereQueryCriteriaException, MaskNotFoundException (+12 more)

### Community 9 - "DateParser & Attribute"
Cohesion: 0.10
Nodes (5): Attribute, DateParser, DateTimeImmutable, EncodingAliases, Header

### Community 10 - "ClientManager"
Cohesion: 0.10
Nodes (8): ClientManager, Client, LoggerInterface, self, Part, Header, TestCase, InitialTest

### Community 11 - "Folder & AbstractProtocol"
Cohesion: 0.11
Nodes (6): AbstractProtocol, HasEvents, Folder, Client, FolderCollection, WhereQuery

### Community 12 - "Docs & Changelog"
Cohesion: 0.11
Nodes (28): Bug Report Issue Template, IMAP IDLE Rework (v4.0.0), PHP 8.0 Support Added (v4.0.0), PHP-IMAP Changelog, AbstractProtocol: Base Protocol Class, Client: Main IMAP Client Class, ClientManager: IMAP Connection Entry Point, Collections: MessageCollection, FolderCollection, etc. (+20 more)

### Community 13 - "Mask Examples"
Cohesion: 0.09
Nodes (5): CustomAttachmentMask, CustomMessageMask, AttachmentMask, Mask, MessageMask

### Community 14 - "Event System"
Cohesion: 0.08
Nodes (11): Event, FlagDeletedEvent, FlagNewEvent, FolderDeletedEvent, FolderMovedEvent, FolderNewEvent, MessageCopiedEvent, MessageDeletedEvent (+3 more)

### Community 15 - "Attribute & ArrayAccess"
Cohesion: 0.12
Nodes (5): ArrayAccess, Attribute, DateTimeImmutable, self, Stringable

### Community 16 - "AbstractProtocol Layer"
Cohesion: 0.15
Nodes (5): ProtocolInterface, AbstractProtocol, Encryption, LoggerInterface, static

### Community 17 - "Attachment"
Cohesion: 0.16
Nodes (4): Attachment, Message, Part, self

## Knowledge Gaps
- **53 isolated node(s):** `name`, `type`, `description`, `keywords`, `homepage` (+48 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **11 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `ClientManager` connect `ClientManager` to `Message & Collections`, `Query Engine`, `Legacy Protocol`, `Client Core`, `DateParser & Attribute`, `Folder & AbstractProtocol`, `Attachment`, `Structure & Header`?**
  _High betweenness centrality (0.341) - this node is a cross-community bridge._
- **Why does `Query` connect `Query Engine` to `WhereQuery Builder`?**
  _High betweenness centrality (0.195) - this node is a cross-community bridge._
- **Why does `LegacyProtocol` connect `Legacy Protocol` to `Folder & AbstractProtocol`?**
  _High betweenness centrality (0.134) - this node is a cross-community bridge._
- **What connects `name`, `type`, `description` to the rest of the system?**
  _53 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Message & Collections` be split into smaller, more focused modules?**
  _Cohesion score 0.0531883632089333 - nodes in this community are weakly interconnected._
- **Should `Query Engine` be split into smaller, more focused modules?**
  _Cohesion score 0.053482221569203646 - nodes in this community are weakly interconnected._
- **Should `IMAP Protocol` be split into smaller, more focused modules?**
  _Cohesion score 0.0745637228979376 - nodes in this community are weakly interconnected._