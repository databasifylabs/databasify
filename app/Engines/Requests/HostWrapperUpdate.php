<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\HostWrapperUpdate as HostWrapperUpdateReply;

/**
 * @implements EngineRequest<HostWrapperUpdateReply>
 */
final readonly class HostWrapperUpdate implements EngineRequest
{
    public function __construct(public string $image) {}

    public function command(): string
    {
        return 'host wrapper-update';
    }

    public function toPayload(): array
    {
        return ['image' => $this->image];
    }

    public function reply(): string
    {
        return HostWrapperUpdateReply::class;
    }
}
