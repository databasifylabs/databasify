<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Lifecycle\EndFailedOperation;
use App\Actions\Databases\Migrations\EndMigration;
use App\Enums\Queues\QueueGroup;
use App\Models\DatabaseMigration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Throwable;

#[Queue(QueueGroup::Migrations)]
#[Tries(1)]
#[Timeout(600)]
final class StopMigrationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public DatabaseMigration $migration, public bool $cutover, public bool $force = false) {}

    public function handle(EndMigration $action): void
    {
        $action->handle($this->migration, $this->cutover, $this->force);
    }

    public function failed(?Throwable $exception): void
    {
        resolve(EndFailedOperation::class)->handle($this->migration->database, $this->cutover ? 'Finish migration' : 'Stop migration', $exception);
    }
}
