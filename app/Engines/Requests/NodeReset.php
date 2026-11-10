<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\Done;

/**
 * @implements EngineRequest<Done>
 */
final readonly class NodeReset implements EngineRequest
{
    /**
     * @param  list<string>  $hosts
     */
    public function __construct(
        public int $peers,
        public array $hosts,
    ) {}

    public function command(): string
    {
        return 'node reset';
    }

    public function toPayload(): array
    {
        return ['peers' => $this->peers, 'hosts' => $this->hosts];
    }

    public function reply(): string
    {
        return Done::class;
    }
}
