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
use Illuminate\Support\Str;
use LogicException;

/**
 * @property string $id
 * @property string $database_id
 * @property class-string<Flow> $flow
 * @property int $step
 * @property FlowContext $context
 * @property string|null $operation_node_id
 * @property string|null $operation_key
 * @property FlowState $state
 * @property string|null $error
 * @property string|null $phase
 * @property Progress|null $progress
 * @property-read Database $database
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['flow', 'step', 'context', 'operation_node_id', 'operation_key', 'state', 'error', 'phase', 'progress'])]
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
        return "{$this->id}.{$this->step}.".Str::lower(Str::random(6));
    }

    public function waitFor(StartedOperation $operation): void
    {
        $this->update(['operation_node_id' => $operation->nodeId, 'operation_key' => $operation->key, 'phase' => null, 'progress' => null]);
    }

    public function forgetOperation(?string $error = null): void
    {
        $this->update(['operation_node_id' => null, 'operation_key' => null, 'error' => $error]);
    }

    public function moveOn(): void
    {
        $this->update(['step' => $this->step + 1, 'operation_node_id' => null, 'operation_key' => null, 'error' => null]);
    }

    public function title(): string
    {
        $title = $this->definition()->name($this->database, $this->context);

        if ($this->phase === null || $this->phase === '') {
            return $title;
        }

        return $this->progress === null ? "{$title}: {$this->phase}" : "{$title}: {$this->phase}, {$this->progress->describe()}";
    }

    protected function casts(): array
    {
        return [
            'step' => 'integer',
            'context' => AsFlowContext::class,
            'state' => FlowState::class,
            'progress' => AsProgress::class,
        ];
    }
}
