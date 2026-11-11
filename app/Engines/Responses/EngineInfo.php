<?php

declare(strict_types=1);

namespace App\Engines\Responses;

use App\Contracts\Engines\EngineReply;
use App\Engines\Payload;

final readonly class EngineInfo implements EngineReply
{
    public function __construct(
        public string $engine,
        public string $line,
        public string $version,
        public string $sdk,
    ) {}

    public static function fromPayload(Payload $payload): static
    {
        return new self(
            engine: $payload->string('engine'),
            line: $payload->string('line'),
            version: $payload->string('version'),
            sdk: $payload->string('sdk'),
        );
    }
}
