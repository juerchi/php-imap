<?php

declare(strict_types=1);

namespace Webklex\PHPIMAP\Support;

/**
 * Pure PHP parser for address lists (RFC 5322 mailbox and group syntax).
 *
 * The result mirrors the address objects of imap_rfc822_parse_headers(), so both implementations are interchangeable:
 *  - a missing host is reported as "UNKNOWN"
 *  - a group is reported as a start marker (mailbox = group name, no host) followed by its members and an empty
 *    end marker
 *  - comments are only used as display name if the address has no other display name and the comment follows it
 */
final class AddressListParser
{
    private const string UNKNOWN_HOST = 'UNKNOWN';

    /**
     * Characters which can not be part of an atom (besides whitespace).
     */
    private const string SPECIALS = '()<>@,;:"[]';

    /**
     * @var list<array{string, string}> [type, value]
     */
    private readonly array $tokens;

    private int $position = 0;

    private function __construct(string $list)
    {
        $this->tokens = self::tokenize($list);
    }

    /**
     * @return list<\stdClass> objects with the optional properties personal, mailbox and host
     */
    public static function parse(string $list): array
    {
        return new self($list)->parseList();
    }

    /**
     * @return list<array{string, string}>
     */
    private static function tokenize(string $input): array
    {
        $tokens = [];
        $length = strlen($input);
        $i = 0;

        while ($i < $length) {
            $char = $input[$i];

            if (ctype_space($char)) {
                ++$i;
            } elseif ('(' === $char) {
                $tokens[] = ['comment', self::readComment($input, $i)];
            } elseif ('"' === $char) {
                $tokens[] = ['quoted', self::readQuoted($input, $i)];
            } elseif ('[' === $char) {
                $end = strpos($input, ']', $i);
                $end = false === $end ? $length - 1 : $end;
                $tokens[] = ['literal', substr($input, $i, $end - $i + 1)];
                $i = $end + 1;
            } elseif (str_contains('<>@,;:', $char)) {
                $tokens[] = [$char, $char];
                ++$i;
            } else {
                $start = $i;
                while ($i < $length && !ctype_space($input[$i]) && !str_contains(self::SPECIALS, $input[$i])) {
                    ++$i;
                }
                if ($i === $start) {
                    ++$i; // stray closing parenthesis or bracket

                    continue;
                }
                $tokens[] = ['word', substr($input, $start, $i - $start)];
            }
        }

        return $tokens;
    }

    /**
     * Read a (nested) comment starting at $i and move $i behind it.
     */
    private static function readComment(string $input, int &$i): string
    {
        $length = strlen($input);
        $depth = 0;
        $text = '';

        for (; $i < $length; ++$i) {
            $char = $input[$i];

            if ('\\' === $char && $i + 1 < $length) {
                $text .= $input[++$i];
            } elseif ('(' === $char) {
                if ($depth++ > 0) {
                    $text .= $char;
                }
            } elseif (')' === $char) {
                if (0 === --$depth) {
                    ++$i;

                    break;
                }
                $text .= $char;
            } else {
                $text .= $char;
            }
        }

        return trim($text);
    }

    /**
     * Read a quoted string starting at $i and move $i behind it.
     */
    private static function readQuoted(string $input, int &$i): string
    {
        $length = strlen($input);
        $text = '';

        for (++$i; $i < $length && '"' !== $input[$i]; ++$i) {
            if ('\\' === $input[$i] && $i + 1 < $length) {
                ++$i;
            }
            $text .= $input[$i];
        }
        ++$i;

        return $text;
    }

    /**
     * @return list<\stdClass>
     */
    private function parseList(): array
    {
        $addresses = [];

        while (null !== ($token = $this->peek())) {
            if (',' === $token[0] || 'comment' === $token[0]) {
                ++$this->position;

                continue;
            }
            if (';' === $token[0]) {
                // a group end without a group: everything behind it is not part of the list
                $addresses[] = new \stdClass();

                break;
            }

            $before = $this->position;
            array_push($addresses, ...$this->parseAddress());
            if ($this->position === $before) {
                ++$this->position; // never stall on an unexpected token
            }
        }

        return $addresses;
    }

    /**
     * @return list<\stdClass>
     */
    private function parseAddress(): array
    {
        $words = [];
        while (null !== ($token = $this->peek())) {
            if ('comment' === $token[0]) {
                ++$this->position;
            } elseif ('word' === $token[0] || 'quoted' === $token[0]) {
                $words[] = $token[1];
                ++$this->position;
            } else {
                break;
            }
        }
        $phrase = implode(' ', $words);

        return match ($this->peek()[0] ?? null) {
            '<' => $this->parseAngleAddress($phrase),
            '@' => [$this->parseAddrSpec($phrase)],
            ':' => $this->parseGroup($phrase),
            default => '' === $phrase ? [] : [self::mailbox($phrase, self::UNKNOWN_HOST)],
        };
    }

    /**
     * Parse "name <local@domain>" with the position on the "<".
     *
     * @return list<\stdClass>
     */
    private function parseAngleAddress(string $personal): array
    {
        ++$this->position;

        if ('@' === ($this->peek()[0] ?? null)) {
            // obsolete source route: <@relay.example.com:user@example.com>
            while (null !== ($token = $this->peek()) && '>' !== $token[0]) {
                ++$this->position;
                if (':' === $token[0]) {
                    break;
                }
            }
        }

        $local = [];
        while (null !== ($token = $this->peek()) && in_array($token[0], ['word', 'quoted'], true)) {
            $local[] = $token[1];
            ++$this->position;
        }

        $host = self::UNKNOWN_HOST;
        if ('@' === ($this->peek()[0] ?? null)) {
            ++$this->position;
            $host = $this->readDomain();
        }

        while (null !== ($token = $this->peek())) {
            ++$this->position;
            if ('>' === $token[0]) {
                break;
            }
        }

        $mailbox = implode('', $local);
        if ('' === $mailbox) {
            return [];
        }

        return [self::mailbox($mailbox, $host, $personal)];
    }

    /**
     * Parse "local@domain" with the position on the "@" and use a trailing comment as display name.
     */
    private function parseAddrSpec(string $local): \stdClass
    {
        ++$this->position;
        $host = $this->readDomain();

        $personal = '';
        if ('comment' === ($this->peek()[0] ?? null)) {
            $personal = $this->peek()[1];
            ++$this->position;
        }

        return self::mailbox($local, $host, $personal);
    }

    /**
     * Parse "group: member, member;" with the position on the ":".
     *
     * @return list<\stdClass>
     */
    private function parseGroup(string $name): array
    {
        ++$this->position;
        $addresses = [(object) ['mailbox' => $name]];

        while (null !== ($token = $this->peek())) {
            if (';' === $token[0]) {
                ++$this->position;

                break;
            }
            if (',' === $token[0] || 'comment' === $token[0]) {
                ++$this->position;

                continue;
            }

            $before = $this->position;
            array_push($addresses, ...$this->parseAddress());
            if ($this->position === $before) {
                ++$this->position;
            }
        }
        $addresses[] = new \stdClass();

        return $addresses;
    }

    private function readDomain(): string
    {
        $token = $this->peek();
        if (null === $token || !in_array($token[0], ['word', 'literal', 'quoted'], true)) {
            return '';
        }
        ++$this->position;

        return $token[1];
    }

    private static function mailbox(string $mailbox, string $host, string $personal = ''): \stdClass
    {
        $address = new \stdClass();
        if ('' !== $personal) {
            $address->personal = $personal;
        }
        $address->mailbox = $mailbox;
        $address->host = $host;

        return $address;
    }

    /**
     * @return null|array{string, string}
     */
    private function peek(): ?array
    {
        return $this->tokens[$this->position] ?? null;
    }
}
