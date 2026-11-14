<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Lifecycle\TearDownDatabase;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Queues\QueueGroup;
use App\Jobs\Concerns\FinishesOperation;
use App\Models\Database;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

#[Queue(QueueGroup::Databases)]
#[Tries(1)]
#[Timeout(3600)]
final class DeleteDatabaseJob implements ShouldQueue
{
    use FinishesOperation, Queueable;

    public function __construct(public Database $database, public bool $eraseData, public bool $force, public bool $deleteBackups = false, public bool $setupFailed = false) {}

    public function handle(TearDownDatabase $action): void
    {
        $action->handle($this->database, $this->eraseData, $this->force, $this->deleteBackups);
    }

    protected function endFailed(Database $database): void
    {
        $this->abandon($database);
    }

    protected function abandon(Database $database): void
    {
        $this->setupFailed ? $database->markFailed() : $database->markReady();
    }

    protected function operatingStatus(): DatabaseStatus
    {
        return DatabaseStatus::Deleting;
    }

    protected function operationName(): string
    {
        return 'Delete database';
    }
}
