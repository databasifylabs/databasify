<?php

declare(strict_types=1);

namespace App\Actions\Databases\Domains;

use App\DTOs\Dns\DnsRecord;
use App\Enums\Dns\DnsRecordType;
use App\Exceptions\Providers\ProviderRequestFailed;
use App\Models\DnsZone;
use Illuminate\Validation\ValidationException;

final readonly class EnsureHostnamesFree
{
    /**
     * @param  list<string>  $names
     */
    public function handle(DnsZone $zone, array $names, string $field): void
    {
        $driver = $zone->provider->provider();

        try {
            $taken = collect($names)->first(fn (string $name): bool => collect($driver->records($zone->external_id, DnsRecordType::A, $name))
                ->contains(fn (DnsRecord $record): bool => ! $record->managed));
        } catch (ProviderRequestFailed $exception) {
            throw ValidationException::withMessages([$field => "Could not read {$zone->name}: {$exception->getMessage()}"]);
        }

        if ($taken !== null) {
            throw ValidationException::withMessages([$field => "{$taken} already has DNS records Databasify didn't create. Remove them or pick another name."]);
        }
    }
}
