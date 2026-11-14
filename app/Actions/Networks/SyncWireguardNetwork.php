<?php

declare(strict_types=1);

namespace App\Actions\Networks;

use App\Enums\Servers\NetworkMemberStatus;
use App\Models\Network;
use Throwable;

final readonly class SyncWireguardNetwork
{
    public const string LOG_NAME = 'Update Databasify network';

    public function __construct(private WireguardScriptData $data) {}

    public function handle(Network $network): void
    {
        foreach ($network->wireguardMembers()->get() as $member) {
            try {
                $member->ssh()->exec('ssh.servers.wireguard.peers', $this->data->handle($member, $network), self::LOG_NAME);
                $member->recordNetworkHealth(NetworkMemberStatus::Connected);
            } catch (Throwable $exception) {
                $member->recordNetworkHealth(NetworkMemberStatus::Unreachable, $exception->getMessage());
            }
        }
    }
}
