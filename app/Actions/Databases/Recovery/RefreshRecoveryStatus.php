<?php

declare(strict_types=1);

namespace App\Actions\Databases\Recovery;

use App\DTOs\Recovery\ArchiveStatus;
use App\Models\PointInTimeRecovery;

final readonly class RefreshRecoveryStatus
{
    public function __construct(private RecordRecoveryStatus $record) {}

    public function handle(PointInTimeRecovery $recovery, bool $backUpNow = false): ArchiveStatus
    {
        $status = $recovery->readStatus($backUpNow)
            ->keepingLatestOf($recovery->latest_restorable_at)
            ->checkingDailyBackupAt(now(), $recovery->created_at);
        $this->record->handle($recovery, $status);

        return $status;
    }
}
