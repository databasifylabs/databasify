<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

final readonly class Endpoint
{
    public function __construct(
        public string $host,
        public int $port,
    ) {}

    /**
     * @return array{host: string, port: int}
     */
    public function toArray(): array
    {
        return ['host' => $this->host, 'port' => $this->port];
    }
}
