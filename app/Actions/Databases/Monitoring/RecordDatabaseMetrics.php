<?php

declare(strict_types=1);

namespace App\Actions\Databases\Monitoring;

use App\DTOs\Metrics\MetricSample;
use App\DTOs\Metrics\NodeMetrics;
use App\Models\Database;
use App\Models\NodeMetricSample;

final readonly class RecordDatabaseMetrics
{
    public function __construct(private ReadDatabaseMetrics $metrics) {}

    public function handle(Database $database): void
    {
        $at = now()->toImmutable();

        $this->metrics->handle($database)
            ->filter(fn (NodeMetrics $metrics): bool => $metrics->reachable)
            ->each(fn (NodeMetrics $metrics) => NodeMetricSample::query()->create(
                MetricSample::from($metrics, $at, $this->latestSampleOf($metrics->nodeId))->toRow(),
            ));
    }

    private function latestSampleOf(string $nodeId): ?NodeMetricSample
    {
        return NodeMetricSample::query()->where('database_node_id', $nodeId)->latest('sampled_at')->first();
    }
}
