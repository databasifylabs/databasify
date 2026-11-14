<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Engines\Drivers\Sentinel\SentinelDriver;
use App\Engines\Drivers\Sentinel\SentinelFamilyEngine;

final readonly class KeyDbDriver extends SentinelDriver
{
    public static function flavor(): SentinelFamilyEngine
    {
        return new SentinelFamilyEngine('keydb', 'keydb-cli', 'keydb-server', 'keydb-check-rdb', 'redis_version');
    }
}
