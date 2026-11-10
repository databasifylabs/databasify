<?php

declare(strict_types=1);

namespace App\Engines\Data;

use App\Models\PointInTimeRecovery;

final readonly class ArchiveSettings
{
    public function __construct(
        public Storage $storage,
        public int $retentionDays,
        public int $backupHour,
    ) {}

    public static function of(PointInTimeRecovery $recovery): self
    {
        return new self(Storage::at($recovery->storageProvider, $recovery->path), $recovery->retention_days, $recovery->backup_hour);
    }

    /**
     * @return array{storage: array<string, string|bool>, retentionDays: int, backupHour: int}
     */
    public function toPayload(): array
    {
        return ['storage' => $this->storage->toPayload(), 'retentionDays' => $this->retentionDays, 'backupHour' => $this->backupHour];
    }
}
