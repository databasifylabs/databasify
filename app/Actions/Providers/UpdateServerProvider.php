<?php

declare(strict_types=1);

namespace App\Actions\Providers;

use App\DTOs\Providers\NewServerProvider;
use App\Exceptions\Providers\ProviderRequestFailed;
use App\Models\Server;
use App\Models\ServerProvider;
use Illuminate\Validation\ValidationException;

final readonly class UpdateServerProvider
{
    public function __construct(private VerifyServerProviderCredentials $credentials) {}

    public function handle(ServerProvider $provider, NewServerProvider $data): ServerProvider
    {
        if ($data->credentials !== $provider->credentials) {
            $driver = $this->credentials->handle(new ServerProvider($data->toRow()));
            $missing = $provider->servers()->whereNotNull('provider_server_id')->get()
                ->reject(function (Server $server) use ($driver): bool {
                    try {
                        $driver->findServer($server->provider_server_id ?? '');

                        return true;
                    } catch (ProviderRequestFailed) {
                        return false;
                    }
                })
                ->map(fn (Server $server): string => $server->name);

            if ($missing->isNotEmpty()) {
                throw ValidationException::withMessages(['credentials' => "These credentials cannot see {$missing->join(', ', ' and ')}. Use credentials for the same account."]);
            }
        }

        $provider->update($data->toRow());

        return $provider;
    }
}
