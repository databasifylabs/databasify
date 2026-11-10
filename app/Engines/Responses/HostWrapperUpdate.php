<?php

declare(strict_types=1);

namespace App\Engines\Responses;

use App\Contracts\Engines\EngineReply;
use App\Engines\Payload;

final readonly class HostWrapperUpdate implements EngineReply
{
    public function __construct(
        public string $wrapper,
        public bool $updated,
    ) {}

    public static function fromPayload(Payload $payload): static
    {
        return new self($payload->string('wrapper'), $payload->bool('updated'));
    }
}
