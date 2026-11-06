<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\DTOs\Providers\ProviderNetwork;
use App\DTOs\Providers\ProviderPlan;
use App\DTOs\Providers\ProviderRegion;
use App\Models\ServerProvider;
use Illuminate\Support\Facades\Cache;

final readonly class ProviderCatalog
{
    private const int CATALOG_SECONDS = 600;

    private const int NETWORK_SECONDS = 60;

    /**
     * @return list<array{id: string, name: string, country: ?string}>
     */
    public function regionsOf(ServerProvider $provider): array
    {
        return Cache::remember("providers:{$provider->id}:regions", self::CATALOG_SECONDS, fn (): array => array_map(
            fn (ProviderRegion $region): array => $region->toArray(),
            $provider->provider()->regions(),
        ));
    }

    /**
     * @return list<array{id: string, family: string, cpus: int, memory_mb: int, disk_gb: int, monthly_price: ?float}>
     */
    public function plansIn(ServerProvider $provider, string $region): array
    {
        return Cache::remember("providers:{$provider->id}:plans:{$region}", self::CATALOG_SECONDS, fn (): array => array_map(
            fn (ProviderPlan $plan): array => $plan->toArray(),
            $provider->provider()->plans($region),
        ));
    }

    /**
     * @return list<array{external_id: string, name: string, cidr: string, region: string, is_default: bool}>
     */
    public function networksIn(ServerProvider $provider, string $region): array
    {
        return Cache::remember("providers:{$provider->id}:networks:{$region}", self::NETWORK_SECONDS, fn (): array => array_map(
            fn (ProviderNetwork $network): array => $network->toArray(),
            $provider->provider()->networks($region),
        ));
    }
}
