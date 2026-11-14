<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Lifecycle\EndFailedOperation;
use App\Actions\Databases\Migrations\RunMigration;
use App\Enums\Queues\QueueGroup;
use App\Models\DatabaseMigration;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Throwable;

#[Queue(QueueGroup::Migrations)]
#[Tries(1)]
#[Timeout(900)]
final class RunMigrationJob implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(public DatabaseMigration $migration) {}

    public function handle(RunMigration $action): void
    {
        $action->handle($this->migration);
    }

    public function failed(?Throwable $exception): void
    {
        resolve(EndFailedOperation::class)->handle($this->migration->database, "Migrate from {$this->migration->source->address()}", $exception);
    }
}
