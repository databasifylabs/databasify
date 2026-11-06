<?php

declare(strict_types=1);

namespace App\Actions\Providers;

use App\DTOs\Providers\NewServerProvider;
use App\Exceptions\Providers\ProviderRequestFailed;
use App\Models\Server;
use App\Models\ServerProvider;
use App\ServerProviders\Contracts\ServerDriver;
use Illuminate\Validation\ValidationException;

final readonly class UpdateServerProvider
{
    public function __construct(private VerifyServerProviderCredentials $credentials) {}

    public function handle(ServerProvider $provider, NewServerProvider $data): ServerProvider
    {
        if ($data->credentials !== $provider->credentials) {
            $this->ensureServersSeenBy($provider, $this->credentials->handle(new ServerProvider($data->toRow())));
        }

        $provider->update($data->toRow());

        return $provider;
    }

    private function ensureServersSeenBy(ServerProvider $provider, ServerDriver $driver): void
    {
        $missing = $provider->servers()->whereNotNull('provider_server_id')->get()
            ->reject(fn (Server $server): bool => $this->sees($driver, $server))
            ->map(fn (Server $server): string => $server->name);

        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['credentials' => "These credentials cannot see {$missing->join(', ', ' and ')}. Use credentials for the same account."]);
        }
    }

    private function sees(ServerDriver $driver, Server $server): bool
    {
        try {
            $driver->findServer($server->provider_server_id ?? '');

            return true;
        } catch (ProviderRequestFailed) {
            return false;
        }
    }
}
