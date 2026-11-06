<?php

declare(strict_types=1);

namespace App\DTOs\Recovery;

final readonly class RecoverySettings
{
    public function __construct(
        public string $storageProviderId,
        public RecoverySchedule $schedule,
    ) {}
}
