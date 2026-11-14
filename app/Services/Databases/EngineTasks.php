<?php

declare(strict_types=1);

namespace App\Services\Databases;

use App\Engines\Data\Operation;
use App\Enums\Databases\OperationState;
use App\Exceptions\Databases\OperationStopped;
use App\Exceptions\Engines\EngineError;
use App\Exceptions\Engines\EngineNotFound;
use App\Exceptions\Engines\EngineNotRunning;
use App\Exceptions\Engines\EngineUnavailable;
use App\Exceptions\Ssh\SshConnectionFailed;
use App\Models\DatabaseNode;
use App\Models\ServerLog;
use Closure;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

final readonly class EngineTasks
{
    private const int POLL_SECONDS = 5;

    /**
     * @param  Closure(string): Operation  $start
     */
    public function await(DatabaseNode $node, string $log, Closure $start): void
    {
        $serverLog = $node->openLog("{$node->server->name}: {$log}");
        $key = "{$node->database_id}.".Str::ulid();
        $start($key);

        while (true) {
            Sleep::for(self::POLL_SECONDS)->seconds();

            if ($node->database->isStopRequested()) {
                $this->stop($node, $key, $serverLog);
            }

            $operation = $this->statusOf($node, $key);

            if ($operation?->state === OperationState::Succeeded) {
                $serverLog->finish(0);

                return;
            }

            if ($operation === null || $operation->state === OperationState::Running) {
                continue;
            }

            $error = $operation->error ?? "{$operation->kind} was {$operation->state->value}.";
            $serverLog->fail($error);

            throw new EngineError($operation->state->value, $error);
        }
    }

    private function statusOf(DatabaseNode $node, string $key): ?Operation
    {
        try {
            return $node->driver()->taskStatus($key);
        } catch (EngineNotFound) {
            return new Operation($key, 'task', OperationState::Interrupted, '', null, 'The engine lost this task.');
        } catch (EngineNotRunning|EngineUnavailable|SshConnectionFailed) {
            return null;
        }
    }

    private function stop(DatabaseNode $node, string $key, ServerLog $serverLog): never
    {
        rescue(fn () => $node->driver()->stopTask($key), report: false);
        $serverLog->fail('Stopped by you.');

        throw new OperationStopped('Stopped by you.');
    }
}
