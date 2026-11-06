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
use Illuminate\Database\Eloquent\Collection;
use Throwable;

final readonly class CheckWireguardNetwork
{
    public function handle(Network $network): void
    {
        $members = $network->wireguardMembers()->get();
        $health = $members->map(fn (Server $member): NetworkHealth => $this->checkOn($member, $network, $members));

        if ($health->contains(fn (NetworkHealth $result): bool => $result->status === NetworkMemberStatus::OutOfSync)) {
            dispatch(new SyncWireguardNetworkJob($network));
        }
    }

    /**
     * @param  Collection<int, Server>  $members
     */
    private function checkOn(Server $member, Network $network, Collection $members): NetworkHealth
    {
        try {
            $state = WireguardStatus::fromOutput($member->ssh()->exec('ssh.servers.wireguard.status'));
            $health = NetworkHealth::of($state, $this->expectedPeersOf($member, $network, $members));
        } catch (Throwable $exception) {
            $health = new NetworkHealth(NetworkMemberStatus::Unreachable, $exception->getMessage());
        }

        $member->recordNetworkHealth($health->status, $health->issue);

        return $health;
    }

    /**
     * @param  Collection<int, Server>  $members
     * @return array<string, string>
     */
    private function expectedPeersOf(Server $member, Network $network, Collection $members): array
    {
        return collect($network->peersOf($member))
            ->mapWithKeys(fn (WireguardPeer $peer): array => [$peer->publicKey => $this->nameOf($peer, $members)])
            ->all();
    }

    /**
     * @param  Collection<int, Server>  $members
     */
    private function nameOf(WireguardPeer $peer, Collection $members): string
    {
        return $members->first(fn (Server $server): bool => $server->wireguard_public_key === $peer->publicKey)->name ?? $peer->address;
    }
}
