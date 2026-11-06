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
        $this->ensureOffered($provider, $order);
        $network = $order->databasifyNetwork === null ? $this->networkFor($provider, $order) : null;

        $server = $this->unsavedServerFor($order, $provider);
        $this->saveOnNetwork($server, $order, $network);

        dispatch(new LaunchProviderServerJob($server));

        return $server;
    }

    private function unsavedServerFor(ProviderServerOrder $order, ServerProvider $provider): Server
    {
        return new Server([
            'name' => $order->name,
            'server_provider_id' => $provider->id,
            'region' => $order->region,
            'plan' => $order->plan,
            'image' => $order->os,
            'ssh_user' => $provider->provider()->sshUser(),
            'status' => ServerStatus::Creating,
        ]);
    }

    private function ensureOffered(ServerProvider $provider, ProviderServerOrder $order): void
    {
        if ($provider->provider()->image($order->os) !== null) {
            return;
        }

        throw ValidationException::withMessages(['os' => "{$provider->label()} does not offer {$order->os->label()}."]);
    }

    private function saveOnNetwork(Server $server, ProviderServerOrder $order, ?Network $network): void
    {
        if ($order->databasifyNetwork !== null) {
            $this->addresses->handle($server, $order->databasifyNetwork);

            return;
        }

        $server->fill((new NetworkAssignment($network))->toRow())->save();
    }

    private function networkFor(ServerProvider $provider, ProviderServerOrder $order): ?Network
    {
        if ($order->newNetwork) {
            return Network::recordFromProvider($provider, $this->createdNetworkFor($provider, $order));
        }

        if ($order->networkExternalId === null) {
            return null;
        }

        return Network::recordFromProvider($provider, $this->chosenNetworkFor($provider, $order));
    }

    private function createdNetworkFor(ServerProvider $provider, ProviderServerOrder $order): ProviderNetwork
    {
        try {
            return $provider->provider()->createNetwork($order->region, 'databasify-'.$order->region.'-'.Str::lower(Str::random(4)));
        } catch (ProviderRequestFailed $exception) {
            throw ValidationException::withMessages(['network' => "{$provider->label()} could not create a private network: {$exception->getMessage()}"]);
        }
    }

    private function chosenNetworkFor(ServerProvider $provider, ProviderServerOrder $order): ProviderNetwork
    {
        $chosen = collect($provider->provider()->networks($order->region))
            ->first(fn (ProviderNetwork $network): bool => $network->externalId === $order->networkExternalId);

        return $chosen ?? throw ValidationException::withMessages(['network' => "That private network is not in {$order->region} anymore."]);
    }
}
