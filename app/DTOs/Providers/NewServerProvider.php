<?php

declare(strict_types=1);

namespace App\DTOs\Providers;

final readonly class NewServerProvider
{
    /**
     * @param  array<string, string>  $credentials
     */
    public function __construct(
        public string $name,
        public string $type,
        public array $credentials,
    ) {}

    /**
     * @return array{name: string, type: string, credentials: array<string, string>}
     */
    public function toRow(): array
    {
        return ['name' => $this->name, 'type' => $this->type, 'credentials' => $this->credentials];
    }
}
