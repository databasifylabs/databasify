<?php

declare(strict_types=1);

namespace App\DTOs\Providers;

final readonly class ProviderNetwork
{
    public function __construct(
        public string $externalId,
        public string $name,
        public string $cidr,
        public string $region,
        public bool $isDefault = false,
    ) {}

    /**
     * @return array{external_id: string, name: string, cidr: string, region: string, is_default: bool}
     */
    public function toArray(): array
    {
        return ['external_id' => $this->externalId, 'name' => $this->name, 'cidr' => $this->cidr, 'region' => $this->region, 'is_default' => $this->isDefault];
    }
}
