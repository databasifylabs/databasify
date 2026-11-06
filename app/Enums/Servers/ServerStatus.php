<?php

declare(strict_types=1);

namespace App\Enums\Servers;

enum ServerStatus: string
{
    case Creating = 'creating';
    case Pending = 'pending';
    case Provisioning = 'provisioning';
    case Ready = 'ready';
    case Failed = 'failed';

    public function isSettingUp(): bool
    {
        return in_array($this, [self::Creating, self::Pending, self::Provisioning], true);
    }
}
