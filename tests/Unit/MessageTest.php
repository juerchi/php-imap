<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;

/**
 * @internal
 *
 * @coversNothing
 */
final class MessageTest extends TestCase
{
    public function testItReturnsBinaryContentUnchanged(): void
    {
        $binary = "\x00\xff\x10binary\r\ncontent\x80";

        $this->assertSame($binary, $this->newMessage()->decodeString($binary, IMAP::MESSAGE_ENC_BINARY));
    }

    public function testItDecodesBase64Content(): void
    {
        $this->assertSame('hello', $this->newMessage()->decodeString('aGVsbG8=', IMAP::MESSAGE_ENC_BASE64));
    }

    private function newMessage(): Message
    {
        return new ReflectionClass(Message::class)->newInstanceWithoutConstructor();
    }
}
