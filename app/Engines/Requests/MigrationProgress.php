<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\MigrationProgress as MigrationProgressReply;

/**
 * @implements EngineRequest<MigrationProgressReply>
 */
final readonly class MigrationProgress implements EngineRequest
{
    public function command(): string
    {
        return 'migration progress';
    }

    public function toPayload(): array
    {
        return [];
    }

    public function reply(): string
    {
        return MigrationProgressReply::class;
    }
}
