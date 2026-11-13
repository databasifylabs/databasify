<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Redis;

use App\Engines\Drivers\Sentinel\SentinelDriver;
use App\Engines\Drivers\Sentinel\SentinelFamilyEngine;

final readonly class RedisDriver extends SentinelDriver
{
    public static function flavor(): SentinelFamilyEngine
    {
        return SentinelFamilyEngine::of('redis');
    }
}
