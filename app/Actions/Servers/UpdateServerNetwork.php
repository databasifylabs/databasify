<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Networks\AssignServerAddress;
use App\DTOs\Servers\NetworkAssignment;
use App\DTOs\Servers\PrivateNetworkChoice;
use App\Exceptions\Ssh\RemoteScriptFailed;
use App\Exceptions\Ssh\SshAccessDenied;
use App\Exceptions\Ssh\SshConnectionFailed;
use App\Jobs\Networks\JoinWireguardNetworkJob;
use App\Jobs\Networks\LeaveWireguardNetworkJob;
use App\Models\Network;
use App\Models\Server;
use Illuminate\Validation\ValidationException;

final readonly class UpdateServerNetwork
{
    public function __construct(private AssignServerAddress $addresses) {}

    public function handle(Server $server, PrivateNetworkChoice $choice): void
    {
        if ($server->isInDatabase()) {
            throw $server->inDatabaseError('network');
        }

        if ($server->isReady() && $choice->privateIp !== null) {
            $this->requireAddressOn($server, $choice->privateIp);
        }

        $previous = $server->network;
        $assignment = $this->addresses->handle($server, $choice);

        $this->moveTunnel($server, $previous, $assignment);
    }

    private function moveTunnel(Server $server, ?Network $previous, NetworkAssignment $assignment): void
    {
        if ($previous?->isWireguard() && $previous->id !== $assignment->network?->id) {
            dispatch(new LeaveWireguardNetworkJob($server, $previous));
        }

        if ($server->isReady() && $assignment->network?->isWireguard()) {
            dispatch(new JoinWireguardNetworkJob($server, $assignment->network));
        }
    }

    private function requireAddressOn(Server $server, string $privateIp): void
    {
        try {
            $server->ssh()->exec('ssh.servers.provision.require-private-ip', ['privateIp' => $privateIp]);
        } catch (RemoteScriptFailed) {
            throw ValidationException::withMessages(['private_ip' => "{$privateIp} is not assigned on {$server->name}."]);
        } catch (SshConnectionFailed|SshAccessDenied $exception) {
            throw ValidationException::withMessages(['private_ip' => $exception->getMessage()]);
        }
    }
}
