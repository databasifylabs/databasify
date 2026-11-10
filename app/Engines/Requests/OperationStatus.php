<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\Operation;

/**
 * @implements EngineRequest<Operation>
 */
final readonly class OperationStatus implements EngineRequest
{
    public function __construct(public string $key) {}

    public function command(): string
    {
        return 'operation status';
    }

    public function toPayload(): array
    {
        return ['key' => $this->key];
    }

    public function reply(): string
    {
        return Operation::class;
    }
}
