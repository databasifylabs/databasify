<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\ConfigTune as ConfigTuneReply;

/**
 * @implements EngineRequest<ConfigTuneReply>
 */
final readonly class ConfigTune implements EngineRequest
{
    public function command(): string
    {
        return 'config tune';
    }

    public function toPayload(): array
    {
        return [];
    }

    public function reply(): string
    {
        return ConfigTuneReply::class;
    }
}
