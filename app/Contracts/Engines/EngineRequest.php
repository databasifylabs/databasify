<?php

declare(strict_types=1);

namespace App\Contracts\Engines;

/**
 * @template-covariant TReply of EngineReply
 */
interface EngineRequest
{
    public function command(): string;

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array;

    /**
     * @return class-string<TReply>
     */
    public function reply(): string;
}
