<?php

declare(strict_types=1);

namespace App\Actions\Databases\Recovery;

use App\DTOs\Recovery\ArchiveStatus;
use App\Enums\Recovery\ArchiveHealth;
use App\Events\DatabaseAlertRaised;
use App\Models\PointInTimeRecovery;
use Illuminate\Support\Facades\DB;

final readonly class RecordRecoveryStatus
{
    public function handle(PointInTimeRecovery $recovery, ArchiveStatus $status): void
    {
        DB::transaction(function () use ($recovery, $status): void {
            $locked = PointInTimeRecovery::query()->lockForUpdate()->find($recovery->id);

            if ($locked === null) {
                return;
            }

            $previous = $locked->health;
            $locked->recordStatus($status->keepingLatestOf($locked->latest_restorable_at)->checkingDailyBackupAt(now(), $locked->created_at));
            $recovery->setRawAttributes($locked->getAttributes(), sync: true);
            $health = $locked->health;

            if ($previous === $health) {
                return;
            }

            $database = $locked->database;

            if ($health === ArchiveHealth::Failing) {
                DB::afterCommit(fn () => event(new DatabaseAlertRaised(
                    $database,
                    "{$database->name}: backup archive needs attention",
                    'The backup archive check failed. Open the Backups tab to inspect the problem and recovery window.',
                )));
            } elseif ($previous === ArchiveHealth::Failing && $health === ArchiveHealth::Healthy) {
                DB::afterCommit(fn () => event(new DatabaseAlertRaised(
                    $database,
                    "{$database->name}: backup archive is healthy again",
                    'The archive is readable again and its recovery window is up to date. This check does not verify a full restore.',
                )));
            }
        });

    }
}
