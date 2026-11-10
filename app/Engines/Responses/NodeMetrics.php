<?php

declare(strict_types=1);

namespace App\Engines\Responses;

use App\Contracts\Engines\EngineReply;
use App\Engines\Payload;

final readonly class NodeMetrics implements EngineReply
{
    public function __construct(
        public int $connections,
        public int $queries,
        public int $dataMb,
    ) {}

    public static function fromPayload(Payload $payload): static
    {
        return new self($payload->int('connections'), $payload->int('queries'), $payload->int('dataMb'));
    }

    /**
     * @return array<string, string>
     */
    public function toValues(): array
    {
        return ['threads_connected' => (string) $this->connections, 'questions' => (string) $this->queries, 'data_mb' => (string) $this->dataMb];
    }
}
