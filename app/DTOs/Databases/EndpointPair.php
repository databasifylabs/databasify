<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

final readonly class EndpointPair
{
    public function __construct(
        public Endpoint $public,
        public Endpoint $vpc,
    ) {}

    /**
     * @return array{public: array{host: string, port: int}, vpc: array{host: string, port: int}}
     */
    public function toArray(): array
    {
        return ['public' => $this->public->toArray(), 'vpc' => $this->vpc->toArray()];
    }
}
