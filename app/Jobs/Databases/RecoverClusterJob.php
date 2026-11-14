<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Failover\RecoverCluster;
use App\Actions\Databases\Lifecycle\EndFailedOperation;
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
final class RecoverClusterJob implements ShouldQueue
{
    use Queueable;

    public string $run;

    /**
     * @param  list<string>  $nodeIds
     */
    public function __construct(
        public Database $database,
        public array $nodeIds,
        public bool $othersDown,
    ) {
        $this->run = strtolower(Str::ulid()->toBase32());
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addDays(7);
    }

    public function handle(RecoverCluster $action, EngineTasks $tasks): void
    {
        if ($tasks->stillRunning($this->database)) {
            $this->release(30);

            return;
        }

        try {
            $action->handle($this->database, $this->nodeIds, $this->othersDown, $this->run);
        } catch (EngineTaskRunning) {
            $this->release(30);
        }
    }

    public function failed(?Throwable $exception): void
    {
        resolve(EndFailedOperation::class)->handle($this->database, 'Recover from a lost primary', $exception);
    }
}
