<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\Done;

/**
 * @implements EngineRequest<Done>
 */
final readonly class ClusterForceQuorum implements EngineRequest
{
    public function __construct(public bool $othersDown) {}

    public function command(): string
    {
        return 'cluster force-quorum';
    }

    public function toPayload(): array
    {
        return ['othersDown' => $this->othersDown];
    }

    public function reply(): string
    {
        return Done::class;
    }
}
