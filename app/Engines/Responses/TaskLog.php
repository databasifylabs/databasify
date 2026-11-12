<?php

declare(strict_types=1);

namespace App\Engines\Responses;

use App\Contracts\Engines\EngineReply;
use App\Engines\Payload;

final readonly class TaskLog implements EngineReply
{
    public function __construct(
        public string $lines,
    ) {}

    public static function fromPayload(Payload $payload): static
    {
        return new self($payload->string('lines'));
    }
}
