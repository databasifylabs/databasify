<?php

declare(strict_types=1);

namespace App\Console\Commands\Databases;

use App\Actions\Storage\QueueFileBackup;
use App\Models\FileBackupDestination;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('databases:file-backups')]
#[Description('Queue due direct FTP, FTPS and SFTP database exports')]
final class QueueFileBackupsCommand extends Command
{
    public function handle(QueueFileBackup $queue): int
    {
        FileBackupDestination::query()->withoutProject()->where('enabled', true)->where('next_backup_at', '<=', now())->lazyById()->each(fn (FileBackupDestination $destination) => $queue->handle($destination));

        return self::SUCCESS;
    }
}
