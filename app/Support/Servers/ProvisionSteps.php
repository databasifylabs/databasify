<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Actions\Networks\JoinWireguardNetwork;
use App\Actions\Servers\ReadServerFacts;
use App\Enums\Servers\OsFamily;

final readonly class ProvisionSteps
{
    /**
     * @return array<string, view-string>
     */
    public static function for(OsFamily $family, bool $disablePasswordLogin): array
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

    /**
     * @return list<string>
     */
    public static function logNames(bool $disablePasswordLogin): array
    {
        return [ReadServerFacts::LOG_NAME, ...array_keys(self::for(OsFamily::Debian, $disablePasswordLogin)), JoinWireguardNetwork::LOG_NAME];
    }
}
