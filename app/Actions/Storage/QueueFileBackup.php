<?php

declare(strict_types=1);

namespace App\Actions\Storage;

use App\Jobs\Databases\RunFileBackupJob;
use App\Models\FileBackupDestination;
use App\Models\FileBackupRun;
use Illuminate\Support\Facades\DB;

final readonly class QueueFileBackup
{
    public function handle(FileBackupDestination $destination): ?FileBackupRun
    {
        return DB::transaction(function () use ($destination): ?FileBackupRun {
            $locked = FileBackupDestination::query()->withoutProject()->lockForUpdate()->find($destination->id);

            if ($locked === null || $locked->runs()->whereIn('status', ['queued', 'running'])->exists()) {
                return null;
            }

            $run = $locked->runs()->create(['status' => 'queued']);
            $locked->update(['next_backup_at' => $locked->nextBackup()]);
            RunFileBackupJob::dispatch($run)->afterCommit();

            return $run;
        });
    }
}
