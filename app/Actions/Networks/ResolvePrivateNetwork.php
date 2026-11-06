<?php

declare(strict_types=1);

namespace App\Actions\Networks;

use App\DTOs\Servers\NetworkAssignment;
use App\DTOs\Servers\PrivateNetworkChoice;
use App\Models\Network;
use App\Models\Server;
use App\Services\Networks\WireguardAddressAllocator;

final readonly class ResolvePrivateNetwork
{
    public function __construct(
        private CreateWireguardNetwork $wireguard,
        private WireguardAddressAllocator $addresses,
    ) {}

    public function handle(PrivateNetworkChoice $choice, ?Server $server = null): NetworkAssignment
    {
        $network = $this->networkFor($choice, $server);

        if ($network === null) {
            return new NetworkAssignment;
        }

        if (! $network->isWireguard()) {
            return new NetworkAssignment($network, $choice->privateIp);
        }

        return new NetworkAssignment($network, $this->addresses->next($network, $server));
    }

    private function networkFor(PrivateNetworkChoice $choice, ?Server $server): ?Network
    {
        return match (true) {
            $choice->newWireguard => $this->wireguard->handle($choice->blockPrefix, collect(array_filter([$server]))),
            $choice->newNetwork !== null => Network::query()->create($choice->newNetwork->toRow()),
            $choice->networkId !== null => Network::query()->findOrFail($choice->networkId),
            default => null,
        };
    }
}
