<?php

declare(strict_types=1);

namespace App\DTOs\Servers;

final readonly class NewNetwork
{
    public function __construct(
        public string $name,
        public string $cidr,
    ) {}

    /**
     * @return array{name: string, cidr: string}
     */
    public function toRow(): array
    {
        return ['name' => $this->name, 'cidr' => $this->cidr];
    }
}
