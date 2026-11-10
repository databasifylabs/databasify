<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\Done;

/**
 * @implements EngineRequest<Done>
 */
final readonly class NodeWake implements EngineRequest
{
    public function __construct(public bool $all) {}

    public function command(): string
    {
        return 'node wake';
    }

    public function toPayload(): array
    {
        return ['all' => $this->all];
    }

    public function reply(): string
    {
        return Done::class;
    }
}
