<?php

declare(strict_types=1);

namespace App\Engines\Responses;

use App\Contracts\Engines\EngineReply;
use App\Engines\Payload;

final readonly class UserCreate implements EngineReply
{
    public function __construct(public bool $created) {}

    public static function fromPayload(Payload $payload): static
    {
        return new self($payload->bool('created'));
    }
}
