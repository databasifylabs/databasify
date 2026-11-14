<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Lifecycle\EndFailedOperation;
use App\Actions\Databases\Lifecycle\EraseDatabaseSetup;
use App\Actions\Databases\Lifecycle\SetUpDatabase;
use App\Enums\Queues\QueueGroup;
use App\Models\Database;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Throwable;

#[Queue(QueueGroup::Databases)]
#[Tries(1)]
#[Timeout(7200)]
#[DeleteWhenMissingModels]
final class CreateDatabaseJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public Database $database) {}

    public function handle(SetUpDatabase $action): void
    {
        $action->handle($this->database);
    }

    public function failed(?Throwable $exception): void
    {
        resolve(EraseDatabaseSetup::class)->handle($this->database);
        resolve(EndFailedOperation::class)->handle($this->database, "Create {$this->database->engine()->label} database", $exception, markFailed: true);
    }
}
