<?php

declare(strict_types=1);

namespace App\Engines\Responses;

use App\Contracts\Engines\EngineReply;
use App\Engines\Payload;

final readonly class ArchiveStatus implements EngineReply
{
    public function __construct(
        public int $backups,
        public ?string $earliest,
        public ?string $latest,
        public ?string $lastBackup,
        public ?string $problem,
    ) {}

    public static function fromPayload(Payload $payload): static
    {
        return new self($payload->int('backups'), $payload->nullableString('earliest'), $payload->nullableString('latest'), $payload->nullableString('lastBackup'), $payload->nullableString('problem'));
    }
}
