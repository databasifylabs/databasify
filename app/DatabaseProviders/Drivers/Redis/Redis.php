<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Drivers\Redis;

use App\DatabaseProviders\Drivers\AbstractSentinelDriver;
use App\Enums\Databases\SentinelFlavor;

final readonly class Redis extends AbstractSentinelDriver
{
    public static function id(): string
    {
        return 'redis';
    }

    public function flavor(): SentinelFlavor
    {
        return SentinelFlavor::Redis;
    }
}
