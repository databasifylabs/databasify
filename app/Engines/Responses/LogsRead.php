<?php

declare(strict_types=1);

namespace App\Engines\Responses;

use App\Contracts\Engines\EngineReply;
use App\Engines\Payload;

final readonly class LogsRead implements EngineReply
{
    /**
     * @param  list<string>  $units
     * @param  list<string>  $lines
     */
    public function __construct(
        public array $units,
        public array $lines,
    ) {}

    public static function fromPayload(Payload $payload): static
    {
        return new self($payload->strings('units'), $payload->strings('lines'));
    }
}
