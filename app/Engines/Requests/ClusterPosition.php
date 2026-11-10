<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\ClusterPosition as ClusterPositionReply;

/**
 * @implements EngineRequest<ClusterPositionReply>
 */
final readonly class ClusterPosition implements EngineRequest
{
    public function command(): string
    {
        return 'cluster position';
    }

    public function toPayload(): array
    {
        return [];
    }

    public function reply(): string
    {
        return ClusterPositionReply::class;
    }
}
