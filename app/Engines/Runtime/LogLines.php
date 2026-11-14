<?php

declare(strict_types=1);

namespace App\Engines\Runtime;

use App\Engines\Payload;

final readonly class LogLines
{
    /**
     * @param  list<string>  $units
     * @param  list<string>  $lines
     */
    public function __construct(
        public array $units,
        public array $lines,
    ) {}

    public static function fromPayload(Payload $payload): self
    {
        return new self($payload->strings('units'), $payload->strings('lines'));
    }
}
