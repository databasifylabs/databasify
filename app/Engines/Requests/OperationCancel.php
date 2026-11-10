<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\Done;

/**
 * @implements EngineRequest<Done>
 */
final readonly class OperationCancel implements EngineRequest
{
    public function __construct(public string $key) {}

    public function command(): string
    {
        return 'operation cancel';
    }

    public function toPayload(): array
    {
        return ['key' => $this->key];
    }

    public function reply(): string
    {
        return Done::class;
    }
}
