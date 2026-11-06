<?php

declare(strict_types=1);

namespace App\DTOs\Networks;

final readonly class WireguardPeer
{
    public function __construct(
        public string $publicKey,
        public string $address,
        public string $publicIp,
        public string $endpoint,
    ) {}
}
