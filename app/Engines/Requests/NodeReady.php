<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\Operation;

/**
 * @implements EngineRequest<Operation>
 */
final readonly class NodeReady implements EngineRequest
{
    public function __construct(
        public string $key,
        public bool $expectPrimary,
    ) {}

    public function command(): string
    {
        return 'node ready';
    }

    public function toPayload(): array
    {
        return ['key' => $this->key, 'expectPrimary' => $this->expectPrimary];
    }

    public function reply(): string
    {
        return Operation::class;
    }
}
