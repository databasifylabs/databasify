<?php

declare(strict_types=1);

namespace App\Engines\Runtime;

use App\Engines\Payload;

final readonly class EngineInfo
{
    public function __construct(
        public string $engine,
        public string $line,
        public string $version,
        public ?string $sdk,
    ) {}

    public static function fromPayload(Payload $payload): self
    {
        return new self($payload->string('engine'), $payload->string('line'), $payload->string('version'), $payload->nullableString('sdk') ?: null);
    }
}
