<?php

declare(strict_types=1);

namespace App\Engines\Responses;

use App\Contracts\Engines\EngineReply;
use App\Engines\Payload;

final readonly class ConfigShow implements EngineReply
{
    /**
     * @param  list<string>  $pendingRestart
     */
    public function __construct(public array $pendingRestart) {}

    public static function fromPayload(Payload $payload): static
    {
        $pending = array_filter($payload->objects('values'), fn (Payload $value): bool => $value->bool('pendingRestart'));

        return new self(array_values(array_map(fn (Payload $value): string => $value->string('key'), $pending)));
    }
}
