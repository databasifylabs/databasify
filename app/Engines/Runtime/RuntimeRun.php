<?php

declare(strict_types=1);

namespace App\Engines\Runtime;

use App\Engines\Payload;

final readonly class RuntimeRun
{
    public function __construct(
        public int $exit,
        public string $stdout,
        public string $stderr,
        public bool $truncated,
    ) {}

    public static function fromPayload(Payload $payload): self
    {
        return new self($payload->int('exit'), $payload->string('stdout'), $payload->string('stderr'), $payload->bool('truncated'));
    }
}
