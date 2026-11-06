<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Migrations\EndMigration;
use App\Enums\Queues\QueueGroup;
use App\Jobs\Concerns\FinishesOperation;
use App\Models\Database;
use App\Models\DatabaseMigration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

#[Queue(QueueGroup::Migrations)]
#[Tries(1)]
#[Timeout(600)]
final class StopMigrationJob implements ShouldQueue
{
    use FinishesOperation, Queueable;

    public function __construct(public DatabaseMigration $migration, public bool $cutover, public bool $force = false) {}

    public function handle(EndMigration $action): void
    {
        $action->handle($this->migration, $this->cutover, $this->force);
    }

    protected function operatedDatabase(): Database
    {
        return $this->migration->database;
    }
}
