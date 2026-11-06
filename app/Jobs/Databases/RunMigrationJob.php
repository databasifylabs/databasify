<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Migrations\RunMigration;
use App\Enums\Queues\QueueGroup;
use App\Jobs\Concerns\FinishesOperation;
use App\Models\Database;
use App\Models\DatabaseMigration;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

#[Queue(QueueGroup::Migrations)]
#[Tries(1)]
#[Timeout(900)]
final class RunMigrationJob implements ShouldBeEncrypted, ShouldQueue
{
    use FinishesOperation, Queueable;

    public function __construct(public DatabaseMigration $migration) {}

    public function handle(RunMigration $action): void
    {
        $action->handle($this->migration);
    }

    protected function operatedDatabase(): Database
    {
        return $this->migration->database;
    }
}
