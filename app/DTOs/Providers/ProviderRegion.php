<?php

declare(strict_types=1);

namespace App\DTOs\Providers;

final readonly class ProviderRegion
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $country = null,
    ) {}

    /**
     * @return array{id: string, name: string, country: ?string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'country' => $this->country];
    }
}
