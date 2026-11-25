<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Header;

/**
 * @internal
 *
 * @coversNothing
 */
final class HeaderTest extends TestCase
{
    protected function setUp(): void
    {
        new ClientManager();
    }

    public function testItParsesAHeaderContainingADate(): void
    {
        $raw = "From: a@example.com\r\nTo: b@example.com\r\nSubject: Hello\r\n"
            ."Date: Wed, 25 Nov 2026 10:00:00 +0100\r\nMessage-ID: <1@example.com>\r\n\r\n";

        $header = new Header($raw);

        $this->assertSame('2026-11-25T10:00:00+01:00', $header->get('date')->toDate()->format('c'));
    }

    #[DataProvider('encodedWordProvider')]
    public function testItDecodesEncodedWordsInTheSubject(string $encoded, string $expected): void
    {
        $header = new Header("From: a@example.com\r\nTo: b@example.com\r\nSubject: {$encoded}\r\n\r\n");

        $this->assertSame($expected, (string) $header->get('subject'));
    }

    #[DataProvider('encodedWordProvider')]
    public function testItDecodesEncodedWordsInTheAddressName(string $encoded, string $expected): void
    {
        $header = new Header("From: {$encoded} <a@example.com>\r\nTo: b@example.com\r\nSubject: x\r\n\r\n");

        $this->assertSame($expected, $header->get('from')->first()->personal);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function encodedWordProvider(): array
    {
        return [
            'utf-8 quoted-printable' => ['=?UTF-8?Q?J=C3=BCrgen_Gr=C3=BC=C3=9Fe?=', 'Jürgen Grüße'],
            'utf-8 base64' => ['=?UTF-8?B?'.base64_encode('Jürgen Grüße').'?=', 'Jürgen Grüße'],
            'iso-8859-1 quoted-printable' => ['=?ISO-8859-1?Q?Gr=FC=DFe?=', 'Grüße'],
            'iso-8859-1 base64' => ['=?ISO-8859-1?B?R3L832U=?=', 'Grüße'],
            'windows-1252' => ['=?windows-1252?Q?Preis_=80_5?=', 'Preis € 5'],
            'multiple words' => ['=?UTF-8?Q?Gr=C3=BC?= =?UTF-8?Q?=C3=9Fe?=', 'Grüße'],
            'plain ascii' => ['Hello', 'Hello'],
        ];
    }

    public function testItParsesHeadersWithBareLineFeeds(): void
    {
        $header = new Header("From: a@example.com\nSubject: lf only\nMessage-ID: <1@example.com>\n\n");

        $this->assertSame('lf only', (string) $header->get('subject'));
        $this->assertSame(['a@example.com'], array_map(strval(...), $header->get('from')->toArray()));
    }

    public function testItUsesTheFirstOfMultipleSubjectHeaders(): void
    {
        $header = new Header("From: a@example.com\r\nSubject: one\r\nSubject: two\r\n\r\n");

        $this->assertSame('one', (string) $header->get('subject'));
    }

    public function testItFallsBackToFromForReplyToAndSender(): void
    {
        $header = new Header("From: Max <max@example.com>\r\nSubject: x\r\n\r\n");

        $this->assertSame('Max <max@example.com>', (string) $header->get('reply_to')->first());
        $this->assertSame('Max <max@example.com>', (string) $header->get('sender')->first());
        $this->assertSame('Max <max@example.com>', (string) $header->get('reply_toaddress'));
        $this->assertSame('Max <max@example.com>', (string) $header->get('senderaddress'));
    }

    public function testItKeepsAnExplicitReplyTo(): void
    {
        $header = new Header("From: a@example.com\r\nReply-To: r@example.com\r\n\r\n");

        $this->assertSame('r@example.com', (string) $header->get('reply_to')->first());
        $this->assertSame('a@example.com', (string) $header->get('sender')->first());
    }

    public function testItKeepsRawUtf8InAddressNames(): void
    {
        $header = new Header("From: \"Müller, Hans\" <h@example.com>\r\nSubject: x\r\n\r\n");

        $this->assertSame('Müller, Hans', $header->get('from')->first()->personal);
    }

    public function testItKeepsRawBytesNextToEncodedWordsInAddressNames(): void
    {
        $header = new Header("From: a@example.com\r\nTo: =?UTF-8?Q?J=C3=BCrgen?= Müller <j@example.com>\r\n\r\n");

        $this->assertSame('Jürgen Müller', $header->get('to')->first()->personal);
    }

    public function testItKeepsRawBytesNextToEncodedWordsInTheSubject(): void
    {
        $header = new Header("From: a@example.com\r\nSubject: Re: =?UTF-8?Q?J=C3=BCrgen?= Müller\r\n\r\n");

        $this->assertSame('Re: Jürgen Müller', (string) $header->get('subject'));
    }

    public function testItDoesNotReplaceRawLatin1BytesInAddressNames(): void
    {
        $header = new Header("From: M\xfcller <m@example.com>\r\nSubject: x\r\n\r\n");

        $this->assertSame("M\xfcller", $header->get('from')->first()->personal);
    }
}
