<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Configuration\RollOutNodeSettings;
use App\Actions\Databases\Lifecycle\EndFailedNodeOperation;
use App\Enums\Queues\QueueGroup;
use App\Exceptions\Engines\EngineTaskRunning;
use App\Models\DatabaseNode;
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
final class ApplyNodeSettingsJob implements ShouldQueue
{
    use Queueable;

    public string $run;

    public function __construct(public DatabaseNode $node)
    {
        $this->run = strtolower(Str::ulid()->toBase32());
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addDays(7);
    }

    public function handle(RollOutNodeSettings $action, EngineTasks $tasks): void
    {
        if ($tasks->stillRunning($this->node)) {
            $this->release(30);

            return;
        }

        try {
            $action->handle($this->node, $this->run);
        } catch (EngineTaskRunning) {
            $this->release(30);
        }
    }

    public function failed(?Throwable $exception): void
    {
        resolve(EndFailedNodeOperation::class)->handle($this->node, 'Apply settings', $exception);
    }
}
