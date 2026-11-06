<?php

declare(strict_types=1);

namespace App\Actions\Dns;

use App\DTOs\Dns\DnsZoneData;
use App\DTOs\Dns\NewDnsProvider;
use App\Exceptions\Providers\ProviderRequestFailed;
use App\Models\DnsProvider;
use Illuminate\Validation\ValidationException;

final readonly class UpdateDnsProvider
{
    public function __construct(private ImportDnsZones $zones) {}

    public function handle(DnsProvider $provider, NewDnsProvider $data): DnsProvider
    {
        $rotated = $data->credentials !== $provider->credentials;

        if ($rotated) {
            $provider->ensureZonesInUseAmong($this->zoneNamesSeenWith($data));
        }

        $provider->update($data->toRow());

        if ($rotated) {
            $this->zones->handle($provider);
        }

        return $provider;
    }

    /**
     * @return list<string>
     */
    private function zoneNamesSeenWith(NewDnsProvider $data): array
    {
        try {
            $driver = (new DnsProvider($data->toRow()))->provider();
            $driver->verify();

            return array_map(fn (DnsZoneData $zone): string => $zone->name, $driver->zones());
        } catch (ProviderRequestFailed $exception) {
            throw ValidationException::withMessages(['credentials' => $exception->getMessage()]);
        }
    }
}
