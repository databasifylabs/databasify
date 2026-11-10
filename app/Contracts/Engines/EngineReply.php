<?php

declare(strict_types=1);

namespace App\Contracts\Engines;

use App\Engines\Payload;

interface EngineReply
{
    public static function fromPayload(Payload $payload): static;
}
