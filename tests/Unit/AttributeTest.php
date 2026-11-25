<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Attribute;

/**
 * @internal
 *
 * @coversNothing
 */
final class AttributeTest extends TestCase
{
    public function testItStringifiesDateValues(): void
    {
        $attribute = new Attribute('date', new DateTimeImmutable('2026-11-25 10:00:00 +0100'));

        $this->assertSame('2026-11-25 10:00:00', (string) $attribute);
    }

    public function testItStringifiesMixedValues(): void
    {
        $attribute = new Attribute('x', ['foo', new DateTimeImmutable('2026-01-02 03:04:05')]);

        $this->assertSame('foo, 2026-01-02 03:04:05', (string) $attribute);
    }
}
