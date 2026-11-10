<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\Done;

/**
 * @implements EngineRequest<Done>
 */
final readonly class MemberForget implements EngineRequest
{
    public function __construct(public string $ip) {}

    public function command(): string
    {
        return 'member forget';
    }

    public function toPayload(): array
    {
        return ['ip' => $this->ip];
    }

    public function reply(): string
    {
        return Done::class;
    }
}
