<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Storage\RunFileBackup;
use App\Enums\Queues\QueueGroup;
use App\Events\DatabaseAlertRaised;
use App\Models\FileBackupRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Throwable;

#[Queue(QueueGroup::Databases)]
#[Tries(1)]
#[Timeout(3600)]
#[FailOnTimeout]
final class RunFileBackupJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public FileBackupRun $run) {}

    public function handle(RunFileBackup $backup): void
    {
        $backup->handle($this->run);
    }

    public function failed(?Throwable $exception): void
    {
        if (! $this->run->exists || ! $this->run->destination()->exists()) {
            return;
        }

        $this->run->update(['status' => 'failed', 'problem' => 'Backup failed or timed out. Verify database availability, free disk space, host tools and destination access, then retry.', 'finished_at' => now()]);
        $database = $this->run->destination->database;
        event(new DatabaseAlertRaised($database, "{$database->name}: file backup failed", 'Open Storage → File backups to inspect the failed run. No successful backup was recorded.'));
    }
}
