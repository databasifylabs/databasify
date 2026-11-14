<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Lifecycle\EndFailedOperation;
use App\Actions\Databases\Migrations\EndMigration;
use App\Enums\Queues\QueueGroup;
use App\Exceptions\Engines\EngineTaskRunning;
use App\Models\DatabaseMigration;
use App\Services\Databases\EngineTasks;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Str;
use Throwable;

#[Queue(QueueGroup::Migrations)]
#[Tries(0)]
#[MaxExceptions(1)]
#[FailOnTimeout]
#[Timeout(3600)]
final class StopMigrationJob implements ShouldQueue
{
    use Queueable;

    public string $run;

    public function __construct(public DatabaseMigration $migration, public bool $cutover, public bool $force = false)
    {
        $this->run = strtolower(Str::ulid()->toBase32());
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addDays(7);
    }

    public function handle(EndMigration $action, EngineTasks $tasks): void
    {
        if ($tasks->stillRunning($this->migration->database)) {
            $this->release(30);

            return;
        }

        try {
            $action->handle($this->migration, $this->cutover, $this->run, $this->force);
        } catch (EngineTaskRunning) {
            $this->release(30);
        }
    }

    public function failed(?Throwable $exception): void
    {
        resolve(EndFailedOperation::class)->handle($this->migration->database, $this->cutover ? 'Finish migration' : 'Stop migration', $exception);
    }
}
