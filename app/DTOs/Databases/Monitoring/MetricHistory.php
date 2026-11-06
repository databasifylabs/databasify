<?php

declare(strict_types=1);

namespace App\DTOs\Databases\Monitoring;

use App\Enums\Databases\MetricPeriod;
use App\Models\DatabaseNode;
use Illuminate\Support\Collection;

final readonly class MetricHistory
{
    /**
     * @param  Collection<int, DatabaseNode>  $nodes
     * @param  Collection<int, MetricPoint>  $points
     */
    public function __construct(
        public MetricPeriod $period,
        public int $bucketSeconds,
        public Collection $nodes,
        public Collection $points,
    ) {}

    /**
     * @return array{period: string, bucket_seconds: int, nodes: list<array{id: string, name: string}>, points: list<array{t: string, values: array<string, array{cpu_percent: float|null, load_1m: float|null, memory_percent: float|null, disk_percent: float|null, connections: float|null, ops_per_second: float|null}>}>}
     */
    public function toArray(): array
    {
        return [
            'period' => $this->period->value,
            'bucket_seconds' => $this->bucketSeconds,
            'nodes' => array_values($this->nodes->map(fn (DatabaseNode $node): array => ['id' => $node->id, 'name' => $node->displayName()])->all()),
            'points' => array_values($this->points->map(fn (MetricPoint $point): array => $point->toArray())->all()),
        ];
    }
}
