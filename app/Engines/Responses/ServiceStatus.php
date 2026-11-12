<?php

declare(strict_types=1);

namespace App\Engines\Responses;

use App\Contracts\Engines\EngineReply;
use App\Engines\Data\ServiceUnit;
use App\Engines\Payload;

final readonly class ServiceStatus implements EngineReply
{
    /**
     * @param  list<ServiceUnit>  $units
     */
    public function __construct(
        public array $units,
    ) {}

    public static function fromPayload(Payload $payload): static
    {
        return new self(array_map(ServiceUnit::fromPayload(...), $payload->objects('units')));
    }
}
