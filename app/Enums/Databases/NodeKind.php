<?php

declare(strict_types=1);

namespace App\Enums\Databases;

enum NodeKind: string
{
    case Member = 'member';
    case ReadOnly = 'read_only';

    public function label(): string
    {
        return match ($this) {
            self::Member => 'Standby',
            self::ReadOnly => 'Read-only',
        };
    }
}
