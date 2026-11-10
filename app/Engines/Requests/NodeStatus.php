<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\NodeStatus as NodeStatusReply;

/**
 * @implements EngineRequest<NodeStatusReply>
 */
final readonly class NodeStatus implements EngineRequest
{
    public function command(): string
    {
        return 'node status';
    }

    public function toPayload(): array
    {
        return [];
    }

    public function reply(): string
    {
        return NodeStatusReply::class;
    }
}
