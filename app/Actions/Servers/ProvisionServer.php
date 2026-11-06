<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Networks\JoinWireguardNetwork;
use App\DTOs\Servers\ServerFacts;
use App\Enums\Servers\OsFamily;
use App\Models\Network;
use App\Models\Server;
use App\Services\Ssh\AppSshKey;
use Throwable;

final readonly class ProvisionServer
{
    public function __construct(
        private AppSshKey $appKey,
        private ReadServerFacts $facts,
        private JoinWireguardNetwork $wireguard,
    ) {}

    /**
     * @return list<string>
     */
    public static function logNames(bool $disablePasswordLogin): array
    {
        return [ReadServerFacts::LOG_NAME, ...array_keys(self::stepsFor(OsFamily::Debian, $disablePasswordLogin)), JoinWireguardNetwork::LOG_NAME];
    }

    public function handle(Server $server, bool $disablePasswordLogin): void
    {
        $server->markProvisioning();

        try {
            $this->provision($server, $this->facts->handle($server), $disablePasswordLogin);
        } catch (Throwable $exception) {
            $server->markFailed();

            throw $exception;
        }

        $server->markProvisioned();
    }

    private function provision(Server $server, ServerFacts $facts, bool $disablePasswordLogin): void
    {
        $network = $server->wireguardNetwork();
        $family = $facts->supportedSystem()->family();
        $data = $this->scriptDataFor($server, $network, $family);

        collect(self::stepsFor($family, $disablePasswordLogin))
            ->each(fn (string $view, string $name) => $server->ssh()->exec($view, $data, $name));

        if ($network === null) {
            return;
        }

        $this->wireguard->handle($server, $network);
    }

    /**
     * @return array<string, mixed>
     */
    private function scriptDataFor(Server $server, ?Network $network, OsFamily $family): array
    {
        return [
            'user' => $server->ssh_user,
            'publicKey' => $this->appKey->get()->public_key,
            'privateIp' => $network === null ? $server->private_ip : null,
            'family' => $family,
            'dockerVersion' => config()->string('databasify.docker.version'),
            'containerdVersion' => config()->string('databasify.docker.containerd'),
        ];
    }

    /**
     * @return array<string, view-string>
     */
    private static function stepsFor(OsFamily $family, bool $disablePasswordLogin): array
    {
        $steps = [
            'Checking the system' => 'ssh.servers.provision.require-private-ip',
            'Installing the Databasify key' => 'ssh.servers.users.install-key',
            'Updating packages' => 'ssh.servers.os.update-packages',
            'Installing base tools' => "ssh.servers.os.{$family->value}.install-base-tools",
            'Installing Docker' => "ssh.servers.os.{$family->value}.install-docker",
            'Adding swap' => 'ssh.servers.os.swap',
        ];

        return $disablePasswordLogin ? [...$steps, 'Turning off password login' => 'ssh.servers.users.disable-password-login'] : $steps;
    }
}
