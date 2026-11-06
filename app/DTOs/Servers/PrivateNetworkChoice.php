<?php

declare(strict_types=1);

namespace App\DTOs\Servers;

use App\Models\Network;

final readonly class PrivateNetworkChoice
{
    public function __construct(
        public ?string $networkId = null,
        public ?NewNetwork $newNetwork = null,
        public ?string $privateIp = null,
        public bool $newWireguard = false,
        public int $blockPrefix = Network::DEFAULT_BLOCK_PREFIX,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public static function newWireguard(int $blockPrefix): self
    {
        return new self(newWireguard: true, blockPrefix: $blockPrefix);
    }
}
