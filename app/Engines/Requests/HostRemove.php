<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\Done;

/**
 * @implements EngineRequest<Done>
 */
final readonly class HostRemove implements EngineRequest
{
    public function __construct(
        public string $databaseId,
        public bool $erase,
    ) {}

    public function command(): string
    {
        return 'host remove';
    }

    public function toPayload(): array
    {
        return ['databaseId' => $this->databaseId, 'erase' => $this->erase];
    }

    public function reply(): string
    {
        return Done::class;
    }
}
