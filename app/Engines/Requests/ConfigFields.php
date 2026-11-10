<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\ConfigFields as ConfigFieldsReply;

/**
 * @implements EngineRequest<ConfigFieldsReply>
 */
final readonly class ConfigFields implements EngineRequest
{
    public function command(): string
    {
        return 'config fields';
    }

    public function toPayload(): array
    {
        return [];
    }

    public function reply(): string
    {
        return ConfigFieldsReply::class;
    }
}
