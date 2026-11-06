<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Networks\AssignServerAddress;
use App\DTOs\Servers\NewServer;
use App\Enums\Servers\ServerStatus;
use App\Models\Server;

final readonly class AddServer
{
    public function __construct(
        private QueueProvisioning $provisioning,
        private AssignServerAddress $addresses,
    ) {}

    public function handle(NewServer $data): Server
    {
        $server = $this->unsavedServerFor($data);
        $this->addresses->handle($server, $data->privateNetwork);

        $this->provisioning->handle($server, $data->disablePasswordLogin);

        return $server;
    }

    private function unsavedServerFor(NewServer $data): Server
    {
        return new Server([
            'name' => $data->name,
            'public_ip' => $data->publicIp,
            'ssh_port' => $data->sshPort,
            'ssh_user' => $data->sshUser,
            'ssh_password' => $data->sshPassword,
            'status' => ServerStatus::Pending,
        ]);
    }
}
