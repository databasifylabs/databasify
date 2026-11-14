<?php

declare(strict_types=1);

namespace App\Services\Databases;

use App\Engines\Data\Operation;
use App\Enums\Databases\OperationState;
use App\Exceptions\Databases\OperationStopped;
use App\Exceptions\Engines\EngineError;
use App\Exceptions\Engines\EngineNotFound;
use App\Exceptions\Engines\EngineNotRunning;
use App\Exceptions\Engines\EngineTaskRunning;
use App\Models\Database;
use App\Models\DatabaseNode;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Sleep;
use RuntimeException;

final readonly class EngineTasks
{
    private const int POLL_SECONDS = 5;

    private const int SHORT_WAIT_SECONDS = 60;

    /**
     * @param  Closure(string): Operation  $start
     */
    public function await(DatabaseNode $node, string $run, string $step, string $log, Closure $start): void
    {
        if ($node->database->isStopRequested()) {
            throw new OperationStopped('Stopped by you.');
        }

        $serverLog = $node->openLog("{$node->server->name}: {$log}");
        $key = $this->keyFor($node, $run, $step);
        $start($key);
        $node->update(['task_key' => $key]);

        for ($waited = 0; ; $waited += self::POLL_SECONDS) {
            if ($node->database->isStopRequested()) {
                rescue(fn () => $node->driver()->stopTask($key), report: false);
                $node->update(['task_key' => null]);
                $serverLog->fail('Stopped by you.');

                throw new OperationStopped('Stopped by you.');
            }

            $operation = $this->statusOf($node, $key);

            if ($operation?->state === OperationState::Succeeded) {
                $node->update(['task_key' => null]);
                $serverLog->finish(0);

                return;
            }

            if ($operation === null || $operation->state === OperationState::Running) {
                if ($waited >= self::SHORT_WAIT_SECONDS) {
                    throw new EngineTaskRunning($key);
                }

                Sleep::for(self::POLL_SECONDS)->seconds();

                continue;
            }

            $failure = $this->failureOf($operation);
            $node->update(['task_key' => null]);
            $serverLog->fail($failure->getMessage());

            throw $failure;
        }
    }

    /**
     * @param  Collection<int, DatabaseNode>  $nodes
     * @param  Closure(DatabaseNode, string): Operation  $start
     */
    public function awaitEach(Collection $nodes, string $run, string $step, string $log, Closure $start): void
    {
        $nodes->each(function (DatabaseNode $node) use ($run, $step, $start): void {
            $key = $this->keyFor($node, $run, $step);
            $start($node, $key);
            $node->update(['task_key' => $key]);
        });
        $nodes->each(fn (DatabaseNode $node) => $this->await($node, $run, $step, $log, fn (string $key): Operation => $node->driver()->taskStatus($key)));
    }

    public function stillRunning(Database $database): bool
    {
        if ($database->isStopRequested()) {
            $this->stopRunning($database);

            throw new OperationStopped('Stopped by you.');
        }

        return $database->nodes()->runningTask()->with('server', 'database')->get()
            ->filter(function (DatabaseNode $node): bool {
                if ($node->task_key === null) {
                    return false;
                }

                $operation = $this->statusOf($node, $node->task_key);

                if ($operation === null || $operation->state === OperationState::Running) {
                    return true;
                }

                $node->update(['task_key' => null]);

                return $operation->state === OperationState::Succeeded ? false : throw $this->failureOf($operation);
            })
            ->isNotEmpty();
    }

    public function started(DatabaseNode $node, string $run, string $step): bool
    {
        try {
            $node->driver()->taskStatus($this->keyFor($node, $run, $step));

            return true;
        } catch (EngineNotFound|EngineNotRunning) {
            return false;
        }
    }

    public function stopRunning(Database $database): void
    {
        $database->nodes()->runningTask()->with('server', 'database')->get()->each(function (DatabaseNode $node): void {
            $key = $node->task_key;

            if ($key !== null) {
                rescue(fn () => $node->driver()->stopTask($key), report: false);
            }

            $node->update(['task_key' => null]);
        });
    }

    private function keyFor(DatabaseNode $node, string $run, string $step): string
    {
        return "{$run}.{$node->id}.{$step}";
    }

    private function statusOf(DatabaseNode $node, string $key): ?Operation
    {
        try {
            return $node->driver()->taskStatus($key);
        } catch (EngineNotFound) {
            return new Operation($key, 'task', OperationState::Interrupted, '', null, 'The engine lost this task.');
        } catch (RuntimeException) {
            return null;
        }
    }

    private function failureOf(Operation $operation): EngineError
    {
        return new EngineError($operation->error ?? "{$operation->kind} was {$operation->state->value}.");
    }
}
