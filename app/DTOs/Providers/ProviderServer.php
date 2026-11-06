<?php

declare(strict_types=1);

namespace App\DTOs\Providers;

final readonly class ProviderServer
{
    public function __construct(
        public string $id,
        public bool $running,
        public ?string $publicIp,
        public ?string $privateIp,
    ) {}

    public function isReachable(): bool
    {
        return $this->running && $this->publicIp !== null;
    }
}
