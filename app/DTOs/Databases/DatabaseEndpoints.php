<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

final readonly class DatabaseEndpoints
{
    public function __construct(
        public Endpoint $public,
        public Endpoint $vpc,
        public ?EndpointPair $standby = null,
    ) {}

    /**
     * @return array{public: array{host: string, port: int}, vpc: array{host: string, port: int}, standby: array{public: array{host: string, port: int}, vpc: array{host: string, port: int}}|null}
     */
    public function toArray(): array
    {
        return ['public' => $this->public->toArray(), 'vpc' => $this->vpc->toArray(), 'standby' => $this->standby?->toArray()];
    }
}
