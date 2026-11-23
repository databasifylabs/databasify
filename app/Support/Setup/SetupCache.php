<?php

declare(strict_types=1);

namespace App\Support\Setup;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

final readonly class SetupCache
{
    private const int OWNER_LOCK_SECONDS = 30;

    public function ownerLock(): Lock
    {
        return Cache::lock('setup:owner', self::OWNER_LOCK_SECONDS);
    }
}
