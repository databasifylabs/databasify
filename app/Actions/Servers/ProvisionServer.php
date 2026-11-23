<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Networks\JoinWireguardNetwork;
use App\Models\Server;
use App\Services\Ssh\AppSshKey;
use App\Support\Servers\ProvisionSteps;

final readonly class ProvisionServer
{
    public function __construct(
        private AppSshKey $appKey,
        private ReadServerFacts $facts,
        private JoinWireguardNetwork $wireguard,
    ) {}

    public function handle(Server $server, bool $disablePasswordLogin): void
    {
        $server->markProvisioning();

        $facts = $this->facts->handle($server);
        $network = $server->wireguardNetwork();
        $family = $facts->supportedSystem()->family();
        $data = [
            'user' => $server->ssh_user,
            'publicKey' => $this->appKey->get()->public_key,
            'privateIp' => $network === null ? $server->private_ip : null,
            'family' => $family,
            'dockerVersion' => config()->string('databasify.docker.version'),
            'containerdVersion' => config()->string('databasify.docker.containerd'),
        ];

        collect(ProvisionSteps::for($family, $disablePasswordLogin))->each(fn (string $view, string $name) => $server->ssh()->exec($view, $data, $name));

        if ($network !== null) {
            $this->wireguard->handle($server, $network);
        }

        $server->markProvisioned();
    }
}
