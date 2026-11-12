<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\Done;

/**
 * @implements EngineRequest<Done>
 */
final readonly class ServiceControl implements EngineRequest
{
    /**
     * @param  list<string>  $units
     */
    public function __construct(
        public string $verb,
        public array $units,
    ) {}

    public function command(): string
    {
        return "service {$this->verb}";
    }

    public function toPayload(): array
    {
        return $this->verb === 'reload' ? [] : ['units' => $this->units];
    }

    public function reply(): string
    {
        return Done::class;
    }
}
