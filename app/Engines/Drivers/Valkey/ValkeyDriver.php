<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Valkey;

use App\Engines\Drivers\Sentinel\SentinelDriver;
use App\Engines\Drivers\Sentinel\SentinelFamilyEngine;

final readonly class ValkeyDriver extends SentinelDriver
{
    public static function flavor(): SentinelFamilyEngine
    {
        return SentinelFamilyEngine::of('valkey');
    }
}
