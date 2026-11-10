<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\EngineCapabilities as EngineCapabilitiesReply;

/**
 * @implements EngineRequest<EngineCapabilitiesReply>
 */
final readonly class EngineCapabilities implements EngineRequest
{
    public function command(): string
    {
        return 'engine capabilities';
    }

    public function toPayload(): array
    {
        return [];
    }

    public function reply(): string
    {
        return EngineCapabilitiesReply::class;
    }
}
