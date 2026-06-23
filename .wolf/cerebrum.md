# Cerebrum

> OpenWolf's learning memory. Updated automatically as the AI learns from interactions.
> Do not edit manually unless correcting an error.
> Last updated: 2026-06-22

## User Preferences

<!-- How the user likes things done. Code style, tools, patterns, communication. -->

## Key Learnings

- **Project:** php-imap
- **Description:** [![Latest release on Packagist][ico-release]][link-packagist]

## Key Learnings

- **Attribute::first()** ruft `array_first($this->values)` auf — nicht `$this->values[0]`. Das war ein Bug (behoben 2026-06-22).
- **Message::get()** hat einen äußeren `isset($this->attributes[$name])`-Check, der früh zurückkehrt. Jeder `case` im darunter liegenden `switch` darf also keinen redundanten `isset`-Check mehr enthalten.
- **$this->messageId** in `Message` ist ein `Attribute`-Objekt, kein String. Für Thread-Vergleiche immer `->first()` aufrufen.
- **peek()/Flag-Vergleiche**: immer `=== null` / `!== null` verwenden, niemals `== null` — FlagCollection gibt null oder einen Flag-String zurück.
- **Yoda-Style** gilt projektübergreifend: `null === $value`, nicht `$value == null`.

## Key Learnings

- **PHPStan level 5 on protocol files**: `fetch()` return type is `array<int, string|array<string,mixed>>|string` — PHPStan cannot narrow it further, so `flags()` and `sizes()` return types must use `array<int, mixed>` rather than precise subtypes.
- **`array_search()` never returns `-1`**: it returns `int<0,max>|false`, so `=== -1` guard is always false and should be removed.
- **`sendRequest()` tokens**: passing `int` where `list<string|array{string,string}>` is expected requires `(string)` cast.
- **`setUidCache()` from `fetch()`**: wrap with `array_values(array_map('intval', $fetched))` to satisfy `list<ImapUid>` type.
- **`iterable<int>` is not a subtype of `array`** in PHPDoc — use `array<int>` for PHP-declared `array|false` return types.

- **`Message::$attributes`** speichert nicht nur `Attribute`-Objekte — `uid`, `msgn`, `size` sind `int|null`. `@var` muss `array<string, Attribute|int|null>` sein.
- **Intelephense P1131 `@return $this`** ist ein bekanntes Intelephense-Limitation — kein PHPStan-Fehler. Nicht anfassen.
- **`mb_convert_encoding()` gibt nie `false` zurück** laut aktuellen PHP-Stubs — `false ===`-Check war redundant (wurde in Folder::decodeName() entfernt).
- **PHPStan `ignore.unmatchedIdentifier`**: ein `@phpstan-ignore`-Tag der nicht mehr greift, wird selbst zum Fehler. Immer nach Fixes prüfen ob bestehende Ignores noch benötigt werden.

## Do-Not-Repeat

<!-- Mistakes made and corrected. Each entry prevents the same mistake recurring. -->
<!-- Format: [YYYY-MM-DD] Description of what went wrong and what to do instead. -->
- [2026-06-22] Nie Attribute-Objekt direkt als string-Parameter übergeben — immer `->first()` oder `(string)` Cast verwenden.
- [2026-06-22] Nie loose `==` / `!=` für null-Vergleiche — immer `=== null` / `!== null` (Yoda-Style).
- [2026-06-22] Nach `make()` das Ergebnis immer auf `null` prüfen, bevor Methoden darauf aufgerufen werden (`populate()` Pattern in Query.php).
- [2026-06-22] Private Methoden mit kryptischen Namen (`x()`) immer sofort umbenennen — kostet nichts, verhindert Verwirrung in Folgesessions.
- [2026-06-22] Beim Erstellen von `getByUidXxx()`-Methoden: `getByUidLower()` = `$id < $uid`, `getByUidLowerThan()` = `$id <= $uid`. Docblock muss exakt die Semantik beschreiben.

## Decision Log

<!-- Significant technical decisions with rationale. Why X was chosen over Y. -->

## Key Learnings (snake_case → camelCase Refactoring, 2026-06-22)

- **Config keys bleiben snake_case**: Nur PHP-Properties/Parameter werden camelCase. Config-Array-Keys (`validate_cert`, `fetch_body`, etc.) bleiben snake_case — Brücke via `UnicodeString::camel()` in `Client::setAccountConfig()`.
- **Message::__call() verwendet camel()**: IMAP-Header kommen als snake_case (z.B. `reply_to`, `in_reply_to`) — `UnicodeString::camel()` wird genutzt, nicht `->snake()`.
- **Attachment::__call() verwendet camel()**: Magic properties sind jetzt camelCase (`imgSrc` statt `img_src`).
- **Event::dispatch() mit new static()**: PHPStan meldet `new.static` auf abstrakten Klassen als Fehler. Fix: `// @phpstan-ignore new.static (intentional: subclasses are always concrete)`.
- **AttachmentMask**: greift direkt auf `$this->parent->contentType` zu (nicht über Getter) — muss bei Property-Umbenennung mitgepflegt werden.
- **PHPStorm rename refactoring** umbenennt fälschlicherweise String-Literale in `ClientManager::get()` Aufrufen — nach Rename-Refactoring Config-Keys in String-Argumenten immer manuell prüfen.
