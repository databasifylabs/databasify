<?php

declare(strict_types=1);

namespace App\Actions\Networks;

use App\Jobs\Networks\SyncWireguardNetworkJob;
use App\Models\Network;
use App\Models\Server;

final readonly class LeaveWireguardNetwork
{
    public const string LOG_NAME = 'Leave Databasify network';

    public function handle(Server $server, Network $network): void
    {
        if ($server->isReady()) {
            $server->ssh()->exec('ssh.servers.wireguard.leave', log: self::LOG_NAME);
        }

        $server->leaveWireguard();
        dispatch(new SyncWireguardNetworkJob($network));
    }
}
