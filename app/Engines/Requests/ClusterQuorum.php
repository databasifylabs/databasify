<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\ClusterQuorum as ClusterQuorumReply;

/**
 * @implements EngineRequest<ClusterQuorumReply>
 */
final readonly class ClusterQuorum implements EngineRequest
{
    public function command(): string
    {
        return 'cluster quorum';
    }

    public function toPayload(): array
    {
        return [];
    }

    public function reply(): string
    {
        return ClusterQuorumReply::class;
    }
}
