<?php

declare(strict_types=1);

namespace App\Engines\Data;

use App\Engines\Payload;

final readonly class ServiceUnit
{
    public function __construct(
        public string $name,
        public string $activeState,
        public string $subState,
        public string $result,
    ) {}

    public static function fromPayload(Payload $payload): self
    {
        return new self($payload->string('name'), $payload->string('activeState'), $payload->string('subState'), $payload->string('result'));
    }
}
