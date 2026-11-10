<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\ArchiveStatus as ArchiveStatusReply;

/**
 * @implements EngineRequest<ArchiveStatusReply>
 */
final readonly class ArchiveStatus implements EngineRequest
{
    public function __construct(public bool $backUpNow) {}

    public function command(): string
    {
        return 'archive status';
    }

    public function toPayload(): array
    {
        return ['backUpNow' => $this->backUpNow];
    }

    public function reply(): string
    {
        return ArchiveStatusReply::class;
    }
}
