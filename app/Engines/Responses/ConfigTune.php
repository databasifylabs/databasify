<?php

declare(strict_types=1);

namespace App\Engines\Responses;

use App\Contracts\Engines\EngineReply;
use App\Engines\Payload;

final readonly class ConfigTune implements EngineReply
{
    /**
     * @param  list<string>  $restart
     */
    public function __construct(public array $restart) {}

    public static function fromPayload(Payload $payload): static
    {
        return new self($payload->strings('restart'));
    }
}
