<?php

declare(strict_types=1);

namespace App\Engines\Responses;

use App\Contracts\Engines\EngineReply;
use App\Engines\Payload;

final readonly class MigrationProgress implements EngineReply
{
    public function __construct(
        public string $copy,
        public string $log,
        public ?int $lagSeconds,
    ) {}

    public static function fromPayload(Payload $payload): static
    {
        return new self($payload->string('copy'), $payload->string('log'), $payload->nullableInt('lagSeconds'));
    }
}
