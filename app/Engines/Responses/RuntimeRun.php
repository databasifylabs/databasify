<?php

declare(strict_types=1);

namespace App\Engines\Responses;

use App\Contracts\Engines\EngineReply;
use App\Engines\Payload;

final readonly class RuntimeRun implements EngineReply
{
    public function __construct(
        public int $exit,
        public string $stdout,
        public string $stderr,
        public bool $truncated,
    ) {}

    public static function fromPayload(Payload $payload): static
    {
        return new self($payload->int('exit'), $payload->string('stdout'), $payload->string('stderr'), $payload->bool('truncated'));
    }
}
