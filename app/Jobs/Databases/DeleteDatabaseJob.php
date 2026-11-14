<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Lifecycle\EndFailedOperation;
use App\Actions\Databases\Lifecycle\TearDownDatabase;
use App\Enums\Queues\QueueGroup;
use App\Models\Database;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Throwable;

#[Queue(QueueGroup::Databases)]
#[Tries(1)]
#[Timeout(3600)]
final class DeleteDatabaseJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public Database $database, public bool $eraseData, public bool $force, public bool $deleteBackups = false, public bool $setupFailed = false) {}

    public function handle(TearDownDatabase $action): void
    {
        $action->handle($this->database, $this->eraseData, $this->force, $this->deleteBackups);
    }

    public function failed(?Throwable $exception): void
    {
        resolve(EndFailedOperation::class)->handle($this->database, 'Delete database', $exception, markFailed: $this->setupFailed);
    }
}
