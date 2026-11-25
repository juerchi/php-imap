<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Support\MimeHeader;

final class MimeHeaderTest extends TestCase
{
    public function testItDecodesEncodedWordsAndKeepsTheRest(): void
    {
        $this->assertSame('Jürgen Müller ü', MimeHeader::decode('=?UTF-8?Q?J=C3=BCrgen?= Müller =?ISO-8859-1?Q?=FC?='));
    }

    public function testItJoinsAdjacentEncodedWords(): void
    {
        $this->assertSame('Grüße', MimeHeader::decode('=?UTF-8?Q?Gr=C3=BC?= =?UTF-8?Q?=C3=9Fe?='));
    }

    public function testItReturnsPlainValuesUnchanged(): void
    {
        $this->assertSame("Müller \xfc", MimeHeader::decode("Müller \xfc"));
    }
}
