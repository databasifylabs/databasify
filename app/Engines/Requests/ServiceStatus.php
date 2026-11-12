<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\ServiceStatus as ServiceStatusReply;

/**
 * @implements EngineRequest<ServiceStatusReply>
 */
final readonly class ServiceStatus implements EngineRequest
{
    /**
     * @param  list<string>  $units
     */
    public function __construct(
        public array $units,
    ) {}

    public function command(): string
    {
        return 'service status';
    }

    public function toPayload(): array
    {
        return ['units' => $this->units];
    }

    public function reply(): string
    {
        return ServiceStatusReply::class;
    }
}
