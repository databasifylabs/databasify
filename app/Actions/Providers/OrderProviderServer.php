<?php

declare(strict_types=1);

namespace App\Actions\Providers;

use App\Actions\Networks\AssignServerAddress;
use App\DTOs\Providers\ProviderNetwork;
use App\DTOs\Providers\ProviderServerOrder;
use App\DTOs\Servers\NetworkAssignment;
use App\Enums\Servers\ServerStatus;
use App\Exceptions\Providers\ProviderRequestFailed;
use App\Jobs\Servers\LaunchProviderServerJob;
use App\Models\Network;
use App\Models\Server;
use App\Models\ServerProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class OrderProviderServer
{
    public function __construct(private AssignServerAddress $addresses) {}

    public function handle(ProviderServerOrder $order): Server
    {
        $provider = ServerProvider::query()->findOrFail($order->serverProviderId);
        $driver = $provider->provider();

        if ($driver->image($order->os) === null) {
            throw ValidationException::withMessages(['os' => "{$provider->label()} does not offer {$order->os->label()}."]);
        }

        $server = new Server([
            'name' => $order->name,
            'server_provider_id' => $provider->id,
            'region' => $order->region,
            'plan' => $order->plan,
            'image' => $order->os,
            'ssh_user' => $driver->sshUser(),
            'status' => ServerStatus::Creating,
        ]);

        if ($order->databasifyNetwork !== null) {
            $this->addresses->handle($server, $order->databasifyNetwork);
            dispatch(new LaunchProviderServerJob($server));

            return $server;
        }

        $network = null;

        if ($order->newNetwork) {
            try {
                $created = $driver->createNetwork($order->region, 'databasify-'.$order->region.'-'.Str::lower(Str::random(4)));
            } catch (ProviderRequestFailed $exception) {
                throw ValidationException::withMessages(['network' => "{$provider->label()} could not create a private network: {$exception->getMessage()}"]);
            }

            $network = Network::recordFromProvider($provider, $created);
        }

        if (! $order->newNetwork && $order->networkExternalId !== null) {
            $chosen = collect($driver->networks($order->region))->first(fn (ProviderNetwork $candidate): bool => $candidate->externalId === $order->networkExternalId)
                ?? throw ValidationException::withMessages(['network' => "That private network is not in {$order->region} anymore."]);
            $network = Network::recordFromProvider($provider, $chosen);
        }

        $server->fill((new NetworkAssignment($network))->toRow())->save();
        dispatch(new LaunchProviderServerJob($server));

        return $server;
    }
}
