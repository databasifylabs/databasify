<?php

declare(strict_types=1);

namespace App\Actions\Networks;

use App\Jobs\Networks\SyncWireguardNetworkJob;
use App\Models\Network;
use App\Models\Server;
use App\Support\Ssh\KeyValueOutput;
use RuntimeException;

final readonly class JoinWireguardNetwork
{
    public const string LOG_NAME = 'Join Databasify network';

    public function __construct(private WireguardScriptData $data) {}

    public function handle(Server $server, Network $network): void
    {
        $output = $server->ssh()->exec('ssh.servers.wireguard.join', $this->data->handle($server, $network), self::LOG_NAME);
        $publicKey = KeyValueOutput::parse($output)['wireguard_public_key'] ?? throw new RuntimeException("{$server->name} did not report its WireGuard key.");

        $server->joinWireguard($publicKey);
        dispatch(new SyncWireguardNetworkJob($network));
    }
}
