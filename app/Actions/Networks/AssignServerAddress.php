<?php

declare(strict_types=1);

namespace App\Actions\Networks;

use App\DTOs\Servers\NetworkAssignment;
use App\DTOs\Servers\PrivateNetworkChoice;
use App\Models\Network;
use App\Models\Server;
use App\Services\Networks\WireguardAddressAllocator;
use Illuminate\Support\Facades\DB;

final readonly class AssignServerAddress
{
    public function __construct(
        private CreateWireguardNetwork $wireguard,
        private WireguardAddressAllocator $addresses,
    ) {}

    public function handle(Server $server, PrivateNetworkChoice $choice): NetworkAssignment
    {
        return DB::transaction(function () use ($server, $choice): NetworkAssignment {
            $network = match (true) {
                $choice->newWireguard => $this->wireguard->handle($choice->blockPrefix, collect([$server])),
                $choice->newNetwork !== null => Network::query()->create($choice->newNetwork->toRow()),
                $choice->networkId !== null => Network::query()->findOrFail($choice->networkId),
                default => null,
            };
            $assignment = new NetworkAssignment($network, $network?->isWireguard() === true ? $this->addresses->next($network, $server) : $choice->privateIp);
            $server->fill($assignment->toRow())->save();

            return $assignment;
        });
    }
}
