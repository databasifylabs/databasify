<?php

declare(strict_types=1);

namespace App\Actions\Databases\Flows;

use App\Engines\EngineClient;
use App\Engines\Flows\StartedOperation;
use App\Engines\Flows\Step;
use App\Engines\Requests\OperationCancel;
use App\Engines\Requests\OperationStatus;
use App\Engines\Responses\Operation;
use App\Enums\Databases\FlowState;
use App\Enums\Databases\OperationState;
use App\Enums\Databases\StepKind;
use App\Exceptions\Engines\EngineNotFound;
use App\Exceptions\Engines\EngineNotRunning;
use App\Exceptions\Engines\EngineUnavailable;
use App\Exceptions\Engines\StepNotReady;
use App\Exceptions\Ssh\SshConnectionFailed;
use App\Models\DatabaseFlow;
use App\Models\DatabaseNode;
use Throwable;

final readonly class AdvanceFlow
{
    private const int MAX_ATTEMPTS = 30;

    public function __construct(
        private EngineClient $engine,
        private FailFlow $fail,
    ) {}

    public function handle(DatabaseFlow $flow): void
    {
        if ($this->stoppedByOwner($flow)) {
            return;
        }

        while ($flow->isRunning()) {
            $advanced = $flow->isWaiting() ? $this->settleOperationOf($flow) : $this->runStepOf($flow);

            if (! $advanced) {
                return;
            }
        }
    }

    private function runStepOf(DatabaseFlow $flow): bool
    {
        $step = $this->currentStepOf($flow);

        if ($step === null) {
            $this->succeed($flow);

            return false;
        }

        try {
            $operation = $this->operationStartedBy($step, $flow);
        } catch (StepNotReady) {
            return false;
        } catch (EngineNotRunning|EngineUnavailable|SshConnectionFailed $exception) {
            return $step->kind === StepKind::Prepare ? $this->stepFailed($flow, $step, $exception->getMessage()) : false;
        } catch (Throwable $exception) {
            return $this->stepFailed($flow, $step, $exception->getMessage());
        }

        if ($operation !== null) {
            $flow->waitFor($operation);

            return false;
        }

        return $this->moveOn($flow);
    }

    private function operationStartedBy(Step $step, DatabaseFlow $flow): ?StartedOperation
    {
        if ($step->kind !== StepKind::Finish) {
            return $step->runFor($flow);
        }

        return $flow->database->ignoringStop(fn (): ?StartedOperation => $step->runFor($flow));
    }

    private function settleOperationOf(DatabaseFlow $flow): bool
    {
        try {
            $operation = $this->engine->send($this->operationNodeOf($flow), new OperationStatus((string) $flow->operation_key));
        } catch (EngineNotFound) {
            return $this->forget($flow);
        } catch (EngineNotRunning|EngineUnavailable|SshConnectionFailed) {
            return false;
        }

        return match ($operation->state) {
            OperationState::Running => $this->track($flow, $operation),
            OperationState::Succeeded => $this->moveOn($flow),
            OperationState::Interrupted => $this->forget($flow),
            OperationState::Failed, OperationState::Cancelled => $this->stepFailed($flow, $this->currentStepOf($flow), $this->failureOf($operation)),
        };
    }

    private function stoppedByOwner(DatabaseFlow $flow): bool
    {
        if (! $flow->isRunning() || ! $flow->database->isStopRequested() || $this->currentStepOf($flow)?->kind === StepKind::Finish) {
            return false;
        }

        if ($flow->isWaiting()) {
            rescue(fn () => $this->engine->send($this->operationNodeOf($flow), new OperationCancel((string) $flow->operation_key)), report: false);
        }

        $this->fail->handle($flow, 'Stopped by you.');

        return true;
    }

    private function track(DatabaseFlow $flow, Operation $operation): bool
    {
        $flow->update(['phase' => $operation->phase, 'progress' => $operation->progress]);
        $flow->database->retitleOperation($flow->title());

        return false;
    }

    private function moveOn(DatabaseFlow $flow): bool
    {
        $flow->moveOn();

        return true;
    }

    private function forget(DatabaseFlow $flow): bool
    {
        if ($flow->attempt + 1 >= self::MAX_ATTEMPTS) {
            $this->fail->handle($flow, 'The engine lost the operation '.self::MAX_ATTEMPTS.' times.');

            return false;
        }

        $flow->forgetOperation();

        return true;
    }

    private function stepFailed(DatabaseFlow $flow, ?Step $step, string $error): bool
    {
        if ($step?->kind === StepKind::Finish && $flow->attempt + 1 < self::MAX_ATTEMPTS) {
            $flow->forgetOperation($error);

            return false;
        }

        $this->fail->handle($flow, $error);

        return false;
    }

    private function succeed(DatabaseFlow $flow): void
    {
        $flow->update(['state' => FlowState::Succeeded, 'error' => null]);
        $flow->database->finishOperation();
    }

    private function operationNodeOf(DatabaseFlow $flow): DatabaseNode
    {
        return DatabaseNode::query()->findOrFail($flow->operation_node_id);
    }

    private function failureOf(Operation $operation): string
    {
        return $operation->error ?? "{$operation->kind} was {$operation->state->value}.";
    }

    private function currentStepOf(DatabaseFlow $flow): ?Step
    {
        return $flow->definition()->steps($flow)[$flow->step] ?? null;
    }
}
