<?php

declare(strict_types=1);

namespace App\Engines\Responses;

use App\Contracts\Engines\EngineReply;
use App\Engines\Data\EngineField;
use App\Engines\Payload;

final readonly class ConfigFields implements EngineReply
{
    /**
     * @param  list<EngineField>  $fields
     */
    public function __construct(public array $fields) {}

    public static function fromPayload(Payload $payload): static
    {
        return new self(array_map(EngineField::fromPayload(...), $payload->objects('fields')));
    }
}
