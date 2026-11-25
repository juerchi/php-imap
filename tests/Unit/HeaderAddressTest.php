<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Header;

/**
 * The expected values are the results of imap_rfc822_parse_headers() (c-client), which the pure PHP parser has to match.
 *
 * @internal
 *
 * @coversNothing
 */
final class HeaderAddressTest extends TestCase
{
    private const array ADDRESS_HEADERS = ['from', 'to', 'cc', 'bcc', 'reply_to', 'sender'];

    protected function setUp(): void
    {
        new ClientManager();
    }

    /**
     * @param list<string>                      $lines
     * @param array<string, list<list<string>>> $expected [personal, mailbox, host, mail, full] per address
     */
    #[DataProvider('addressProvider')]
    public function testItParsesAddressHeadersLikeExtImap(array $lines, array $expected): void
    {
        $header = new Header(implode("\r\n", $lines)."\r\n\r\n");

        foreach (self::ADDRESS_HEADERS as $name) {
            $actual = array_map(
                static fn (Address $a): array => [$a->personal, $a->mailbox, $a->host, $a->mail, $a->full],
                $header->get($name)->toArray(),
            );

            $this->assertSame($expected[$name] ?? [], $actual, $name);
        }
    }

    /**
     * @return array<string, array{list<string>, array<string, list<list<string>>>}>
     */
    public static function addressProvider(): array
    {
        return [
            'simple' => [
                ['From: Max Mustermann <max@example.com>', 'To: a@example.com'],
                [
                    'from' => [
                        ['Max Mustermann', 'max', 'example.com', 'max@example.com', 'Max Mustermann <max@example.com>'],
                    ],
                    'to' => [
                        ['', 'a', 'example.com', 'a@example.com', 'a@example.com'],
                    ],
                    'reply_to' => [
                        ['Max Mustermann', 'max', 'example.com', 'max@example.com', 'Max Mustermann <max@example.com>'],
                    ],
                    'sender' => [
                        ['Max Mustermann', 'max', 'example.com', 'max@example.com', 'Max Mustermann <max@example.com>'],
                    ],
                ],
            ],
            'bare' => [
                ['From: max@example.com', 'To: <a@example.com>'],
                [
                    'from' => [
                        ['', 'max', 'example.com', 'max@example.com', 'max@example.com'],
                    ],
                    'to' => [
                        ['', 'a', 'example.com', 'a@example.com', 'a@example.com'],
                    ],
                    'reply_to' => [
                        ['', 'max', 'example.com', 'max@example.com', 'max@example.com'],
                    ],
                    'sender' => [
                        ['', 'max', 'example.com', 'max@example.com', 'max@example.com'],
                    ],
                ],
            ],
            'list' => [
                ['To: a@example.com, b@example.com, c@example.com'],
                [
                    'to' => [
                        ['', 'a', 'example.com', 'a@example.com', 'a@example.com'],
                        ['', 'b', 'example.com', 'b@example.com', 'b@example.com'],
                        ['', 'c', 'example.com', 'c@example.com', 'c@example.com'],
                    ],
                ],
            ],
            'list-names' => [
                ['To: A <a@example.com>, B <b@example.com>'],
                [
                    'to' => [
                        ['A', 'a', 'example.com', 'a@example.com', 'A <a@example.com>'],
                        ['B', 'b', 'example.com', 'b@example.com', 'B <b@example.com>'],
                    ],
                ],
            ],
            'quoted-comma' => [
                ['To: "Y, X" <b@example.com>, c@example.com'],
                [
                    'to' => [
                        ['Y, X', 'b', 'example.com', 'b@example.com', 'Y, X <b@example.com>'],
                        ['', 'c', 'example.com', 'c@example.com', 'c@example.com'],
                    ],
                ],
            ],
            'quoted-escaped' => [
                ['To: "Say \"hi\"" <b@example.com>'],
                [
                    'to' => [
                        ['Say "hi"', 'b', 'example.com', 'b@example.com', 'Say "hi" <b@example.com>'],
                    ],
                ],
            ],
            'comment' => [
                ['From: max@example.com (Max M.)'],
                [
                    'from' => [
                        ['Max M.', 'max', 'example.com', 'max@example.com', 'Max M. <max@example.com>'],
                    ],
                    'reply_to' => [
                        ['Max M.', 'max', 'example.com', 'max@example.com', 'Max M. <max@example.com>'],
                    ],
                    'sender' => [
                        ['Max M.', 'max', 'example.com', 'max@example.com', 'Max M. <max@example.com>'],
                    ],
                ],
            ],
            'comment-before' => [
                ['From: (Max M.) max@example.com'],
                [
                    'from' => [
                        ['', 'max', 'example.com', 'max@example.com', 'max@example.com'],
                    ],
                    'reply_to' => [
                        ['', 'max', 'example.com', 'max@example.com', 'max@example.com'],
                    ],
                    'sender' => [
                        ['', 'max', 'example.com', 'max@example.com', 'max@example.com'],
                    ],
                ],
            ],
            'group' => [
                ['To: Team: x@example.com, y@example.com;'],
                [
                    'to' => [
                        ['', 'Team', '', '', ''],
                        ['', 'x', 'example.com', 'x@example.com', 'x@example.com'],
                        ['', 'y', 'example.com', 'y@example.com', 'y@example.com'],
                        ['', '', '', '', ''],
                    ],
                ],
            ],
            'group-empty' => [
                ['To: undisclosed-recipients:;'],
                [
                    'to' => [
                        ['', 'undisclosed-recipients', '', '', ''],
                        ['', '', '', '', ''],
                    ],
                ],
            ],
            'group-named' => [
                ['To: Team <x@example.com>, undisclosed-recipients:;'],
                [
                    'to' => [
                        ['Team', 'x', 'example.com', 'x@example.com', 'Team <x@example.com>'],
                        ['', 'undisclosed-recipients', '', '', ''],
                        ['', '', '', '', ''],
                    ],
                ],
            ],
            'folded' => [
                ['To: b@example.com,', ' c@example.com'],
                [
                    'to' => [
                        ['', 'b', 'example.com', 'b@example.com', 'b@example.com'],
                        ['', 'c', 'example.com', 'c@example.com', 'c@example.com'],
                    ],
                ],
            ],
            'folded-name' => [
                ['To: Very Long Name', '  <b@example.com>'],
                [
                    'to' => [
                        ['Very Long Name', 'b', 'example.com', 'b@example.com', 'Very Long Name <b@example.com>'],
                    ],
                ],
            ],
            'encoded-name' => [
                ['To: =?UTF-8?Q?J=C3=BCrgen?= <j@example.com>'],
                [
                    'to' => [
                        ['Jürgen', 'j', 'example.com', 'j@example.com', 'Jürgen <j@example.com>'],
                    ],
                ],
            ],
            'encoded-quoted' => [
                ['To: "=?ISO-8859-1?Q?M=FCller?=" <m@example.com>'],
                [
                    'to' => [
                        ['Müller', 'm', 'example.com', 'm@example.com', 'Müller <m@example.com>'],
                    ],
                ],
            ],
            'single-quote' => [
                ['To: \'Max\' <m@example.com>'],
                [
                    'to' => [
                        ['Max', 'm', 'example.com', 'm@example.com', 'Max <m@example.com>'],
                    ],
                ],
            ],
            'no-host' => [
                ['To: localuser'],
                [
                    'to' => [
                        ['', 'localuser', 'UNKNOWN', 'localuser@UNKNOWN', 'localuser@UNKNOWN'],
                    ],
                ],
            ],
            'no-host-name' => [
                ['To: Local <localuser>'],
                [
                    'to' => [
                        ['Local', 'localuser', 'UNKNOWN', 'localuser@UNKNOWN', 'Local <localuser@UNKNOWN>'],
                    ],
                ],
            ],
            'route' => [
                ['To: <@relay.example.com:user@example.com>'],
                [
                    'to' => [
                        ['', 'user', 'example.com', 'user@example.com', 'user@example.com'],
                    ],
                ],
            ],
            'plus-tag' => [
                ['To: a+tag@example.com'],
                [
                    'to' => [
                        ['', 'a+tag', 'example.com', 'a+tag@example.com', 'a+tag@example.com'],
                    ],
                ],
            ],
            'trailing-comma' => [
                ['To: a@example.com,'],
                [
                    'to' => [
                        ['', 'a', 'example.com', 'a@example.com', 'a@example.com'],
                    ],
                ],
            ],
            'empty' => [
                ['To: '],
                [],
            ],
            'cc-bcc-sender' => [
                ['From: a@example.com', 'Sender: s@example.com', 'Cc: c@example.com', 'Bcc: d@example.com', 'Reply-To: r@example.com'],
                [
                    'from' => [
                        ['', 'a', 'example.com', 'a@example.com', 'a@example.com'],
                    ],
                    'cc' => [
                        ['', 'c', 'example.com', 'c@example.com', 'c@example.com'],
                    ],
                    'bcc' => [
                        ['', 'd', 'example.com', 'd@example.com', 'd@example.com'],
                    ],
                    'reply_to' => [
                        ['', 'r', 'example.com', 'r@example.com', 'r@example.com'],
                    ],
                    'sender' => [
                        ['', 's', 'example.com', 's@example.com', 's@example.com'],
                    ],
                ],
            ],
            'reply-default' => [
                ['From: a@example.com'],
                [
                    'from' => [
                        ['', 'a', 'example.com', 'a@example.com', 'a@example.com'],
                    ],
                    'reply_to' => [
                        ['', 'a', 'example.com', 'a@example.com', 'a@example.com'],
                    ],
                    'sender' => [
                        ['', 'a', 'example.com', 'a@example.com', 'a@example.com'],
                    ],
                ],
            ],
            'sender-default' => [
                ['From: a@example.com', 'To: b@example.com'],
                [
                    'from' => [
                        ['', 'a', 'example.com', 'a@example.com', 'a@example.com'],
                    ],
                    'to' => [
                        ['', 'b', 'example.com', 'b@example.com', 'b@example.com'],
                    ],
                    'reply_to' => [
                        ['', 'a', 'example.com', 'a@example.com', 'a@example.com'],
                    ],
                    'sender' => [
                        ['', 'a', 'example.com', 'a@example.com', 'a@example.com'],
                    ],
                ],
            ],
            'dotted-name' => [
                ['From: John Q. Public <j@example.com>'],
                [
                    'from' => [
                        ['John Q. Public', 'j', 'example.com', 'j@example.com', 'John Q. Public <j@example.com>'],
                    ],
                    'reply_to' => [
                        ['John Q. Public', 'j', 'example.com', 'j@example.com', 'John Q. Public <j@example.com>'],
                    ],
                    'sender' => [
                        ['John Q. Public', 'j', 'example.com', 'j@example.com', 'John Q. Public <j@example.com>'],
                    ],
                ],
            ],
            'unquoted-comma' => [
                ['To: Public, John <j@example.com>'],
                [
                    'to' => [
                        ['', 'Public', 'UNKNOWN', 'Public@UNKNOWN', 'Public@UNKNOWN'],
                        ['John', 'j', 'example.com', 'j@example.com', 'John <j@example.com>'],
                    ],
                ],
            ],
            'semicolon' => [
                ['To: a@example.com; b@example.com'],
                [
                    'to' => [
                        ['', 'a', 'example.com', 'a@example.com', 'a@example.com'],
                        ['', '', '', '', ''],
                    ],
                ],
            ],
            'two-from' => [
                ['From: a@example.com, b@example.com'],
                [
                    'from' => [
                        ['', 'a', 'example.com', 'a@example.com', 'a@example.com'],
                        ['', 'b', 'example.com', 'b@example.com', 'b@example.com'],
                    ],
                    'reply_to' => [
                        ['', 'a', 'example.com', 'a@example.com', 'a@example.com'],
                        ['', 'b', 'example.com', 'b@example.com', 'b@example.com'],
                    ],
                    'sender' => [
                        ['', 'a', 'example.com', 'a@example.com', 'a@example.com'],
                        ['', 'b', 'example.com', 'b@example.com', 'b@example.com'],
                    ],
                ],
            ],
            'ip-host' => [
                ['To: user@[192.168.0.1]'],
                [
                    'to' => [
                        ['', 'user', '[192.168.0.1]', 'user@[192.168.0.1]', 'user@[192.168.0.1]'],
                    ],
                ],
            ],
            'upper' => [
                ['TO: A <A@EXAMPLE.COM>'],
                [
                    'to' => [
                        ['A', 'A', 'EXAMPLE.COM', 'A@EXAMPLE.COM', 'A <A@EXAMPLE.COM>'],
                    ],
                ],
            ],
        ];
    }
}
