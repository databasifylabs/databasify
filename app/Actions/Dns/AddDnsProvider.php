<?php

declare(strict_types=1);

namespace App\Actions\Dns;

use App\DTOs\Dns\NewDnsProvider;
use App\Exceptions\Providers\ProviderRequestFailed;
use App\Models\DnsProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class AddDnsProvider
{
    public function __construct(private ImportDnsZones $zones) {}

    public function handle(NewDnsProvider $data): DnsProvider
    {
        $provider = new DnsProvider($data->toRow());

        try {
            $provider->provider()->verify();
        } catch (ProviderRequestFailed $exception) {
            throw ValidationException::withMessages(['credentials' => $exception->getMessage()]);
        }

        return DB::transaction(function () use ($provider): DnsProvider {
            $provider->save();
            $this->zones->handle($provider);

            return $provider;
        });
    }
}
