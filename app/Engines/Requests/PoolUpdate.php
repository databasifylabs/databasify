<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Data\Pool;
use App\Engines\Responses\Done;

/**
 * @implements EngineRequest<Done>
 */
final readonly class PoolUpdate implements EngineRequest
{
    public function __construct(public Pool $pool) {}

    public function command(): string
    {
        return 'pool update';
    }

    public function toPayload(): array
    {
        return $this->pool->toPayload();
    }

    public function reply(): string
    {
        return Done::class;
    }
}
