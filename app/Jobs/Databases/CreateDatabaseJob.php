<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Lifecycle\EndFailedOperation;
use App\Actions\Databases\Lifecycle\EraseDatabaseSetup;
use App\Actions\Databases\Lifecycle\SetUpDatabase;
use App\Enums\Queues\QueueGroup;
use App\Exceptions\Engines\EngineTaskRunning;
use App\Models\Database;
use App\Services\Databases\EngineTasks;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Str;
use Throwable;

#[Queue(QueueGroup::Databases)]
#[Tries(0)]
#[MaxExceptions(1)]
#[FailOnTimeout]
#[Timeout(3600)]
#[DeleteWhenMissingModels]
final class CreateDatabaseJob implements ShouldQueue
{
    use Queueable;

    public string $run;

    public function __construct(public Database $database)
    {
        $this->run = strtolower(Str::ulid()->toBase32());
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addDays(7);
    }

    public function handle(SetUpDatabase $action, EngineTasks $tasks): void
    {
        if ($tasks->stillRunning($this->database)) {
            $this->release(30);

            return;
        }

        try {
            $action->handle($this->database, $this->run);
        } catch (EngineTaskRunning) {
            $this->release(30);
        }
    }

    public function failed(?Throwable $exception): void
    {
        resolve(EraseDatabaseSetup::class)->handle($this->database);
        resolve(EndFailedOperation::class)->handle($this->database, "Create {$this->database->engine()->label} database", $exception, markFailed: true);
    }
}
