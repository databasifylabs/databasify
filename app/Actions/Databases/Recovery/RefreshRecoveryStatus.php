<?php

declare(strict_types=1);

namespace App\Actions\Databases\Recovery;

use App\DTOs\Recovery\ArchiveStatus;
use App\Models\PointInTimeRecovery;

final readonly class RefreshRecoveryStatus
{
    public function handle(PointInTimeRecovery $recovery, bool $backUpNow = false): ArchiveStatus
    {
        $status = $recovery->readStatus($backUpNow)->keepingLatestOf($recovery->latest_restorable_at);
        $recovery->recordStatus($status);

        return $status;
    }
}
