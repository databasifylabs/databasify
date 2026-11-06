<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Recovery\DeleteRecoveryBackups;
use App\DTOs\Recovery\StoredArchive;
use App\Enums\Queues\QueueGroup;
use App\Models\Database;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

#[Queue(QueueGroup::Databases)]
#[Tries(1)]
#[Timeout(3600)]
final class DeleteRecoveryBackupsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public Database $database, public StoredArchive $archive) {}

    public function handle(DeleteRecoveryBackups $action): void
    {
        $action->handle($this->database, $this->archive);
    }
}
