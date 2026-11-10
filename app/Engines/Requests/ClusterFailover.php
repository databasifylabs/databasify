<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\Done;

/**
 * @implements EngineRequest<Done>
 */
final readonly class ClusterFailover implements EngineRequest
{
    public function __construct(public string $target) {}

    public function command(): string
    {
        return 'cluster failover';
    }

    public function toPayload(): array
    {
        return ['target' => $this->target];
    }

    public function reply(): string
    {
        return Done::class;
    }
}
