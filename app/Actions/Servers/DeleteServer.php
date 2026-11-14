<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Exceptions\Providers\ProviderRequestFailed;
use App\Jobs\Networks\SyncWireguardNetworkJob;
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class DeleteServer
{
    public function handle(Server $server, bool $destroyAtProvider = false): void
    {
        $server->ensureDeletable();

        if ($server->isBeingCreated()) {
            throw ValidationException::withMessages(['server' => "{$server->name} is still being created, wait until it finishes."]);
        }

        $provider = $server->serverProvider;

        if ($destroyAtProvider && $provider !== null && $server->provider_server_id !== null) {
            try {
                $provider->provider()->deleteServer($server->provider_server_id);
            } catch (ProviderRequestFailed $exception) {
                throw ValidationException::withMessages(['server' => "Could not destroy {$server->name} at {$provider->label()}: {$exception->getMessage()}"]);
            }
        }

        $network = $server->wireguardNetwork();
        DB::transaction(fn () => $server->delete());

        if ($network !== null) {
            dispatch(new SyncWireguardNetworkJob($network));
        }
    }
}
