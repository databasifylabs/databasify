<?php

declare(strict_types=1);

namespace App\Engines\Responses;

use App\Contracts\Engines\EngineReply;
use App\Engines\Payload;

final readonly class RuntimeInfo implements EngineReply
{
    public function __construct(
        public int $protocol,
        public string $engine,
        public string $sdk,
    ) {}

    public static function fromPayload(Payload $payload): static
    {
        return new self($payload->int('protocol'), $payload->string('engine'), $payload->string('sdk'));
    }
}
