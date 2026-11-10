<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\Done;

/**
 * @implements EngineRequest<Done>
 */
final readonly class ConfigSync implements EngineRequest
{
    public function command(): string
    {
        return 'config sync';
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
