<?php

declare(strict_types=1);

namespace App\DTOs\Servers;

final readonly class NewServer
{
    public function __construct(
        public string $name,
        public string $publicIp,
        public int $sshPort,
        public string $sshUser,
        public ?string $sshPassword,
        public bool $disablePasswordLogin,
        public PrivateNetworkChoice $privateNetwork = new PrivateNetworkChoice,
    ) {}
}
