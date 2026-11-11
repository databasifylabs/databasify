<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\AsFlowContext;
use App\Casts\AsProgress;
use App\Contracts\Engines\Flow;
use App\Engines\Data\Progress;
use App\Engines\Flows\FlowContext;
use App\Engines\Flows\StartedOperation;
use App\Enums\Databases\FlowState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $id
 * @property string $database_id
 * @property class-string<Flow> $flow
 * @property int $step
 * @property int $attempt
 * @property FlowContext $context
 * @property string|null $operation_node_id
 * @property string|null $operation_key
 * @property string|null $operation_log_id
 * @property FlowState $state
 * @property string|null $error
 * @property string|null $phase
 * @property Progress|null $progress
 * @property-read Database $database
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['flow', 'step', 'attempt', 'context', 'operation_node_id', 'operation_key', 'operation_log_id', 'state', 'error', 'phase', 'progress'])]
class DatabaseFlow extends Model
{
    use HasUuids;

    /**
     * @return BelongsTo<Database, $this>
     */
    public function database(): BelongsTo
    {
        return $this->belongsTo(Database::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function running(Builder $query): void
    {
        $query->where('state', FlowState::Running);
    }

    public function definition(): Flow
    {
        $definition = app($this->flow);

        throw_unless($definition instanceof Flow, LogicException::class, "{$this->flow} is not a flow.");

        return $definition;
    }

    public function isRunning(): bool
    {
        return $this->state === FlowState::Running;
    }

    public function isWaiting(): bool
    {
        return $this->operation_key !== null;
    }

    public function newOperationKey(): string
    {
        return "{$this->id}.{$this->step}.{$this->attempt}";
    }

    /**
     * @return BelongsTo<ServerLog, $this>
     */
    public function operationLog(): BelongsTo
    {
        return $this->belongsTo(ServerLog::class);
    }

    public function waitFor(StartedOperation $operation): void
    {
        $this->update(['operation_node_id' => $operation->nodeId, 'operation_key' => $operation->key, 'operation_log_id' => $operation->logId, 'phase' => null, 'progress' => null]);
    }

    public function forgetOperation(?string $error = null): void
    {
        $this->closeOperationLog($error ?? 'The engine lost this operation, so the step starts again.');
        $this->update(['operation_node_id' => null, 'operation_key' => null, 'operation_log_id' => null, 'error' => $error, 'attempt' => $this->attempt + 1]);
    }

    public function moveOn(): void
    {
        $this->closeOperationLog();
        $this->update(['step' => $this->step + 1, 'attempt' => 0, 'operation_node_id' => null, 'operation_key' => null, 'operation_log_id' => null, 'error' => null]);
    }

    public function fail(string $error): void
    {
        $this->closeOperationLog($error);
        $this->update(['state' => FlowState::Failed, 'error' => $error, 'operation_node_id' => null, 'operation_key' => null, 'operation_log_id' => null]);
    }

    public function title(): string
    {
        $title = $this->definition()->name($this->database, $this->context);

        if ($this->phase === null || $this->phase === '') {
            return $title;
        }

        return $this->progress === null ? "{$title}: {$this->phase}" : "{$title}: {$this->phase}, {$this->progress->describe()}";
    }

    private function closeOperationLog(?string $error = null): void
    {
        $log = $this->operationLog()->first();

        if ($log === null || ! $log->isRunning()) {
            return;
        }

        if ($error === null) {
            $log->finish(0);

            return;
        }

        $log->fail($error);
    }

    protected function casts(): array
    {
        return [
            'step' => 'integer',
            'attempt' => 'integer',
            'context' => AsFlowContext::class,
            'state' => FlowState::class,
            'progress' => AsProgress::class,
        ];
    }
}
