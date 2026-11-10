<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\ConfigShow as ConfigShowReply;

/**
 * @implements EngineRequest<ConfigShowReply>
 */
final readonly class ConfigShow implements EngineRequest
{
    public function command(): string
    {
        return 'config show';
    }

    public function toPayload(): array
    {
        return [];
    }

    public function reply(): string
    {
        return ConfigShowReply::class;
    }
}
