<?php

declare(strict_types=1);

namespace App\Actions\Databases\Monitoring;

use App\DTOs\Databases\Monitoring\MetricHistory;
use App\DTOs\Databases\Monitoring\MetricPoint;
use App\DTOs\Databases\Monitoring\NodeAverages;
use App\Enums\Databases\MetricPeriod;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\NodeMetricSample;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

final readonly class ReadMetricHistory
{
    public function handle(Database $database, MetricPeriod $period, ?DatabaseNode $node = null): MetricHistory
    {
        $nodes = $this->nodesShown($database, $node);

        return new MetricHistory($period, $period->bucketSeconds(), $nodes, $this->pointsOf($nodes, $period));
    }

    /**
     * @param  EloquentCollection<int, DatabaseNode>  $nodes
     * @return Collection<int, MetricPoint>
     */
    private function pointsOf(EloquentCollection $nodes, MetricPeriod $period): Collection
    {
        return NodeMetricSample::query()
            ->whereBelongsTo($nodes, 'node')
            ->since(now()->subSeconds($period->seconds()))
            ->averagedPerNodeIn($period->bucketSeconds())
            ->get()
            ->groupBy('bucket_start')
            ->map(fn (Collection $samples, int $start): MetricPoint => new MetricPoint(
                now()->setTimestamp($start),
                $samples->map(NodeAverages::fromSample(...)),
            ))
            ->values();
    }

    /**
     * @return EloquentCollection<int, DatabaseNode>
     */
    private function nodesShown(Database $database, ?DatabaseNode $node): EloquentCollection
    {
        return $database->nodes()
            ->when($node, fn (Builder $query, DatabaseNode $node) => $query->whereKey($node->id), fn (Builder $query) => $query->members())
            ->get();
    }
}
