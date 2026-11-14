<?php

declare(strict_types=1);

namespace App\Enums\Databases;

enum DatabaseStatus: string
{
    case Pending = 'pending';
    case Creating = 'creating';
    case Updating = 'updating';
    case Upgrading = 'upgrading';
    case Rebooting = 'rebooting';
    case Redeploying = 'redeploying';
    case Scaling = 'scaling';
    case Recovering = 'recovering';
    case Migrating = 'migrating';
    case Deleting = 'deleting';
    case Ready = 'ready';
    case Failed = 'failed';

    public function isBusy(): bool
    {
        return $this !== self::Ready && $this !== self::Failed;
    }

    public function isSettingUp(): bool
    {
        return $this === self::Pending || $this === self::Creating;
    }
}
