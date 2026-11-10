<?php

declare(strict_types=1);

namespace App\Actions\Databases\Flows;

use App\Engines\EngineClient;
use App\Engines\Flows\Step;
use App\Engines\Requests\OperationStatus;
use App\Engines\Responses\Operation;
use App\Enums\Databases\FlowState;
use App\Enums\Databases\OperationState;
use App\Enums\Databases\StepKind;
use App\Exceptions\Engines\EngineNotFound;
use App\Exceptions\Engines\EngineNotRunning;
use App\Exceptions\Engines\EngineUnavailable;
use App\Exceptions\Ssh\SshConnectionFailed;
use App\Models\DatabaseFlow;
use App\Models\DatabaseNode;
use Throwable;

final readonly class AdvanceFlow
{
    public function __construct(
        private EngineClient $engine,
        private FailFlow $fail,
    ) {}

    public function handle(DatabaseFlow $flow): void
    {
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
            $operation = $step->runFor($flow);
        } catch (Throwable $exception) {
            return $this->stepFailed($flow, $step, $exception->getMessage());
        }

        if ($operation !== null) {
            $flow->waitFor($operation);

            return false;
        }

        return $this->moveOn($flow);
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
        $flow->forgetOperation();

        return true;
    }

    private function stepFailed(DatabaseFlow $flow, ?Step $step, string $error): bool
    {
        if ($step?->kind === StepKind::Finish) {
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
