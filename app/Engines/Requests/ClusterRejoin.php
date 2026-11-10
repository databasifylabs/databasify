<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\Operation;

/**
 * @implements EngineRequest<Operation>
 */
final readonly class ClusterRejoin implements EngineRequest
{
    public function __construct(
        public string $key,
        public bool $readOnly,
    ) {}

    public function command(): string
    {
        return 'cluster rejoin';
    }

    public function toPayload(): array
    {
        return ['key' => $this->key, 'readOnly' => $this->readOnly];
    }

    public function reply(): string
    {
        return Operation::class;
    }
}
