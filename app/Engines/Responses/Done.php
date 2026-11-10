<?php

declare(strict_types=1);

namespace App\Engines\Responses;

use App\Contracts\Engines\EngineReply;
use App\Engines\Payload;

final readonly class Done implements EngineReply
{
    public static function fromPayload(Payload $payload): static
    {
        return new self;
    }
}
