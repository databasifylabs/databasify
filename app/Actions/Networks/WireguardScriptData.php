<?php

declare(strict_types=1);

namespace App\Actions\Networks;

use App\DTOs\Networks\WireguardPeer;
use App\Models\Network;
use App\Models\Server;
use RuntimeException;

final readonly class WireguardScriptData
{
    /**
     * @return array<string, mixed>
     */
    public function handle(Server $server, Network $network): array
    {
        $peers = $network->peersOf($server);

        return [
            'address' => $server->private_ip ?? throw new RuntimeException("{$server->name} has no Databasify network address."),
            'range' => $network->cidr,
            'prefix' => explode('/', $network->cidr)[1] ?? '24',
            'listenPort' => $network->listenPort(),
            'peers' => $peers,
            'memberIps' => array_map(fn (WireguardPeer $peer): string => $peer->publicIp, $peers),
        ];
    }
}
