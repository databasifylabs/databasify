<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\PoolList as PoolListReply;

/**
 * @implements EngineRequest<PoolListReply>
 */
final readonly class PoolList implements EngineRequest
{
    public function command(): string
    {
        return 'pool list';
    }

    public function toPayload(): array
    {
        return [];
    }

    public function reply(): string
    {
        return PoolListReply::class;
    }
}
