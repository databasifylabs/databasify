<?php

declare(strict_types=1);

namespace App\Actions\Networks;

use App\Enums\Servers\NetworkMemberStatus;
use App\Models\Network;
use App\Models\Server;
use Throwable;

final readonly class SyncWireguardNetwork
{
    public const string LOG_NAME = 'Update Databasify network';

    public function __construct(private WireguardScriptData $data) {}

    public function handle(Network $network): void
    {
        $network->wireguardMembers()->get()
            ->each(fn (Server $member) => $this->updatePeersOn($member, $network));
    }

    private function updatePeersOn(Server $member, Network $network): void
    {
        try {
            $member->ssh()->exec('ssh.servers.wireguard.peers', $this->data->handle($member, $network), self::LOG_NAME);
        } catch (Throwable $exception) {
            $member->recordNetworkHealth(NetworkMemberStatus::Unreachable, $exception->getMessage());

            return;
        }

        $member->recordNetworkHealth(NetworkMemberStatus::Connected);
    }
}
