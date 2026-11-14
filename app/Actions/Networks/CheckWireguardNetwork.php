<?php

declare(strict_types=1);

namespace App\Actions\Networks;

use App\DTOs\Networks\NetworkHealth;
use App\DTOs\Networks\WireguardPeer;
use App\DTOs\Networks\WireguardStatus;
use App\Enums\Servers\NetworkMemberStatus;
use App\Jobs\Networks\SyncWireguardNetworkJob;
use App\Models\Network;
use App\Models\Server;
use Throwable;

final readonly class CheckWireguardNetwork
{
    public function handle(Network $network): void
    {
        $members = $network->wireguardMembers()->get();
        $outOfSync = false;

        foreach ($members as $member) {
            try {
                $state = WireguardStatus::fromOutput($member->ssh()->exec('ssh.servers.wireguard.status'));
                $expected = collect($network->peersOf($member))
                    ->mapWithKeys(fn (WireguardPeer $peer): array => [$peer->publicKey => $members->first(fn (Server $server): bool => $server->wireguard_public_key === $peer->publicKey)->name ?? $peer->address])
                    ->all();
                $health = NetworkHealth::of($state, $expected);
            } catch (Throwable $exception) {
                $health = new NetworkHealth(NetworkMemberStatus::Unreachable, $exception->getMessage());
            }

            $member->recordNetworkHealth($health->status, $health->issue);
            $outOfSync = $outOfSync || $health->status === NetworkMemberStatus::OutOfSync;
        }

        if ($outOfSync) {
            dispatch(new SyncWireguardNetworkJob($network));
        }
    }
}
