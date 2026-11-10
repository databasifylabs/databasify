<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\Done;

/**
 * @implements EngineRequest<Done>
 */
final readonly class ClusterDisable implements EngineRequest
{
    public function command(): string
    {
        return 'cluster disable';
    }

    public function toPayload(): array
    {
        return [];
    }

    public function reply(): string
    {
        return Done::class;
    }
}
