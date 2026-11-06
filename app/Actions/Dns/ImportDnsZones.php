<?php

declare(strict_types=1);

namespace App\Actions\Dns;

use App\DTOs\Dns\DnsZoneData;
use App\Exceptions\Providers\ProviderRequestFailed;
use App\Models\DnsProvider;
use App\Models\DnsZone;
use Illuminate\Validation\ValidationException;

final readonly class ImportDnsZones
{
    public function handle(DnsProvider $provider): int
    {
        try {
            $zones = $provider->provider()->zones();
        } catch (ProviderRequestFailed $exception) {
            throw ValidationException::withMessages(['provider' => "Could not list the domains: {$exception->getMessage()}"]);
        }

        collect($zones)->each(fn (DnsZoneData $zone) => $this->keep($provider, $zone));
        $this->forgetMissing($provider, $zones);

        return count($zones);
    }

    private function keep(DnsProvider $provider, DnsZoneData $zone): void
    {
        DnsZone::query()->updateOrCreate(
            ['dns_provider_id' => $provider->id, 'name' => $zone->name],
            ['external_id' => $zone->externalId],
        );
    }

    /**
     * @param  list<DnsZoneData>  $zones
     */
    private function forgetMissing(DnsProvider $provider, array $zones): void
    {
        $provider->zones()
            ->whereNotIn('name', array_map(fn (DnsZoneData $zone): string => $zone->name, $zones))
            ->doesntHave('databases')
            ->delete();
    }
}
