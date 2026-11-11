<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\EngineInfo as EngineInfoReply;

/**
 * @implements EngineRequest<EngineInfoReply>
 */
final readonly class EngineInfo implements EngineRequest
{
    public function command(): string
    {
        return 'engine info';
    }

    public function toPayload(): array
    {
        return [];
    }

    public function reply(): string
    {
        return EngineInfoReply::class;
    }
}
