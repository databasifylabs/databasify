<?php

declare(strict_types=1);

namespace App\Enums\Migrations;

enum MigrationStatus: string
{
    case Copying = 'copying';
    case Syncing = 'syncing';
    case Completed = 'completed';
    case Stopped = 'stopped';
    case Failed = 'failed';

    /**
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Copying, self::Syncing];
    }

    public function isActive(): bool
    {
        return in_array($this, self::active(), true);
    }
}
