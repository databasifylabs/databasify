<?php

declare(strict_types=1);

namespace App\Support\Providers;

use App\Models\ServerProvider;
use Closure;
use Illuminate\Support\Facades\Cache;

final readonly class ProviderCatalogCache
{
    private const int CATALOG_SECONDS = 600;

    private const int NETWORK_SECONDS = 60;

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $read
     * @return TValue
     */
    public function regions(ServerProvider $provider, Closure $read): mixed
    {
        return Cache::remember("providers:{$provider->id}:regions", self::CATALOG_SECONDS, $read);
    }

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $read
     * @return TValue
     */
    public function plans(ServerProvider $provider, string $region, Closure $read): mixed
    {
        return Cache::remember("providers:{$provider->id}:plans:{$region}", self::CATALOG_SECONDS, $read);
    }

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $read
     * @return TValue
     */
    public function networks(ServerProvider $provider, string $region, Closure $read): mixed
    {
        return Cache::remember("providers:{$provider->id}:networks:{$region}", self::NETWORK_SECONDS, $read);
    }
}
