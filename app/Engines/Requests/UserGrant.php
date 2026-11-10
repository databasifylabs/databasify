<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Data\Grant;
use App\Engines\Responses\Done;

/**
 * @implements EngineRequest<Done>
 */
final readonly class UserGrant implements EngineRequest
{
    /**
     * @param  list<Grant>  $grants
     */
    public function __construct(
        public string $name,
        public array $grants,
        public bool $replication = false,
    ) {}

    public function command(): string
    {
        return 'user grant';
    }

    public function toPayload(): array
    {
        return ['name' => $this->name, 'grants' => array_map(fn (Grant $grant): array => $grant->toPayload(), $this->grants), 'replication' => $this->replication];
    }

    public function reply(): string
    {
        return Done::class;
    }
}
