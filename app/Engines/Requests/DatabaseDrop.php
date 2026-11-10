<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\Done;

/**
 * @implements EngineRequest<Done>
 */
final readonly class DatabaseDrop implements EngineRequest
{
    public function __construct(public string $name) {}

    public function command(): string
    {
        return 'database drop';
    }

    public function toPayload(): array
    {
        return ['name' => $this->name];
    }

    public function reply(): string
    {
        return Done::class;
    }
}
