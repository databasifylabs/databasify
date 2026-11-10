<?php

declare(strict_types=1);

namespace App\Engines\Responses;

use App\Contracts\Engines\EngineReply;
use App\Engines\Payload;

final readonly class ClusterPosition implements EngineReply
{
    public function __construct(public ?int $position) {}

    public static function fromPayload(Payload $payload): static
    {
        return new self($payload->nullableInt('position'));
    }
}
