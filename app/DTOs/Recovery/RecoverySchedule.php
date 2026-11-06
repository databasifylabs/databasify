<?php

declare(strict_types=1);

namespace App\DTOs\Recovery;

final readonly class RecoverySchedule
{
    public const int MAX_RETENTION_DAYS = 35;

    public function __construct(
        public int $retentionDays,
        public int $backupHour,
    ) {}

    /**
     * @return array{retention_days: int, backup_hour: int}
     */
    public function toRow(): array
    {
        return ['retention_days' => $this->retentionDays, 'backup_hour' => $this->backupHour];
    }
}
