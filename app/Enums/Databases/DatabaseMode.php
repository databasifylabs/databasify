<?php

declare(strict_types=1);

namespace App\Enums\Databases;

enum DatabaseMode: string
{
    case Single = 'single';
    case HighAvailability = 'ha';

    public static function forMembers(int $members): self
    {
        return $members > 1 ? self::HighAvailability : self::Single;
    }
}
