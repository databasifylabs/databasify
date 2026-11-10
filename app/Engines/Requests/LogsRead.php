<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\LogsRead as LogsReadReply;

/**
 * @implements EngineRequest<LogsReadReply>
 */
final readonly class LogsRead implements EngineRequest
{
    public function __construct(
        public ?string $unit,
        public int $lines,
    ) {}

    public function command(): string
    {
        return 'logs read';
    }

    public function toPayload(): array
    {
        return ['unit' => $this->unit, 'lines' => $this->lines];
    }

    public function reply(): string
    {
        return LogsReadReply::class;
    }
}
