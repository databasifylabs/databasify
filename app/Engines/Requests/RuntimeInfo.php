<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\RuntimeInfo as RuntimeInfoReply;

/**
 * @implements EngineRequest<RuntimeInfoReply>
 */
final readonly class RuntimeInfo implements EngineRequest
{
    public function command(): string
    {
        return 'runtime info';
    }

    public function toPayload(): array
    {
        return [];
    }

    public function reply(): string
    {
        return RuntimeInfoReply::class;
    }
}
