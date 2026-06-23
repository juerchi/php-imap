<?php

declare(strict_types=1);

namespace Webklex\PHPIMAP\Enums;

enum Encryption: string
{
    case NoTls = 'notls';
    case Ssl = 'ssl';
    case Tls = 'tls';
    case StartTls = 'starttls';

    public function defaultPort(): int
    {
        return match ($this) {
            self::Ssl => 993,
            default => 143,
        };
    }

    public function toFlag(): string
    {
        return '/'.match ($this) {
            self::StartTls => 'tls',
            default => $this->value,
        };
    }
}
