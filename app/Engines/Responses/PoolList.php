<?php

declare(strict_types=1);

namespace App\Engines\Responses;

use App\Contracts\Engines\EngineReply;
use App\Engines\Data\Pool;
use App\Engines\Payload;

final readonly class PoolList implements EngineReply
{
    /**
     * @param  list<Pool>  $pools
     */
    public function __construct(public array $pools) {}

    public static function fromPayload(Payload $payload): static
    {
        return new self(array_map(Pool::fromPayload(...), $payload->objects('pools')));
    }
}
