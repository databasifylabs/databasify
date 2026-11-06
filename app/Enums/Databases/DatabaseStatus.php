<?php

declare(strict_types=1);

namespace App\Enums\Databases;

enum DatabaseStatus: string
{
    case Creating = 'creating';
    case Ready = 'ready';
    case Degraded = 'degraded';
    case Offline = 'offline';
    case Failed = 'failed';

    public function isServing(): bool
    {
        return $this === self::Ready || $this === self::Degraded || $this === self::Offline;
    }
}
