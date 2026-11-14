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

        foreach ($zones as $zone) {
            DnsZone::query()->updateOrCreate(['dns_provider_id' => $provider->id, 'name' => $zone->name], ['external_id' => $zone->externalId]);
        }

        $provider->zones()
            ->whereNotIn('name', array_map(fn (DnsZoneData $zone): string => $zone->name, $zones))
            ->doesntHave('databases')
            ->delete();

        return count($zones);
    }
}
