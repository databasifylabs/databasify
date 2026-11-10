<?php

declare(strict_types=1);

namespace App\Engines\Responses;

use App\Contracts\Engines\EngineReply;
use App\Engines\Payload;

final readonly class ClusterQuorum implements EngineReply
{
    public function __construct(
        public int $total,
        public int $online,
    ) {}

    public function hasMajority(): bool
    {
        return $this->online * 2 > $this->total;
    }

    public static function fromPayload(Payload $payload): static
    {
        return new self($payload->int('total'), $payload->int('online'));
    }
}
