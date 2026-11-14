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
use Illuminate\Support\Collection;

final readonly class ReadMetricHistory
{
    public function handle(Database $database, MetricPeriod $period, ?DatabaseNode $node = null): MetricHistory
    {
        $nodes = $database->nodes()
            ->when($node, fn (Builder $query, DatabaseNode $node) => $query->whereKey($node->id), fn (Builder $query) => $query->members())
            ->get();
        $points = NodeMetricSample::query()
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

        return new MetricHistory($period, $period->bucketSeconds(), $nodes, $points);
    }
}
