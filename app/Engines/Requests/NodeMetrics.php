<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\NodeMetrics as NodeMetricsReply;

/**
 * @implements EngineRequest<NodeMetricsReply>
 */
final readonly class NodeMetrics implements EngineRequest
{
    public function command(): string
    {
        return 'node metrics';
    }

    public function toPayload(): array
    {
        return [];
    }

    public function reply(): string
    {
        return NodeMetricsReply::class;
    }
}
