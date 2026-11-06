<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Databases\ResourceMetric;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $database_node_id
 * @property ResourceMetric $metric
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable|null $resolved_at
 * @property-read DatabaseNode $node
 */
#[Fillable(['database_node_id', 'metric', 'opened_at', 'resolved_at'])]
#[WithoutTimestamps]
class NodeAlert extends Model
{
    use HasUuids;

    /**
     * @return BelongsTo<DatabaseNode, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(DatabaseNode::class, 'database_node_id');
    }

    public function resolve(): void
    {
        $this->update(['resolved_at' => now()]);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    protected function casts(): array
    {
        return [
            'metric' => ResourceMetric::class,
            'opened_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
