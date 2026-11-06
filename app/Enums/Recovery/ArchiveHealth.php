<?php

declare(strict_types=1);

namespace App\Enums\Recovery;

enum ArchiveHealth: string
{
    case Pending = 'pending';
    case Healthy = 'healthy';
    case Failing = 'failing';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for the first base backup',
            self::Healthy => 'Archiving',
            self::Failing => 'Archiving needs attention',
        };
    }
}
