<?php

declare(strict_types=1);

namespace App\Enums\Databases;

enum NodeRole: string
{
    case Primary = 'primary';
    case Replica = 'replica';
    case Offline = 'offline';
    case Unknown = 'unknown';

    public function serves(): bool
    {
        return $this === self::Primary || $this === self::Replica;
    }

    public function isReachable(): bool
    {
        return $this !== self::Unknown;
    }
}
