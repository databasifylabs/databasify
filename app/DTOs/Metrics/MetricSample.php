<?php

declare(strict_types=1);

namespace App\DTOs\Metrics;

use App\Models\NodeMetricSample;
use Carbon\CarbonImmutable;

final readonly class MetricSample
{
    public function __construct(
        public string $nodeId,
        public CarbonImmutable $sampledAt,
        public ?float $cpuPercent,
        public ?float $load1m,
        public ?float $memoryPercent,
        public ?float $diskPercent,
        public ?int $connections,
        public ?float $opsPerSecond,
        public ?int $queryTotal,
    ) {}

    public static function from(NodeMetrics $metrics, CarbonImmutable $at, ?NodeMetricSample $previous): self
    {
        $values = $metrics->values;
        $queryTotal = self::integer($values['questions'] ?? null);

        return new self(
            nodeId: $metrics->nodeId,
            sampledAt: $at,
            cpuPercent: self::number($values['cpu_percent'] ?? null),
            load1m: self::number($values['load_1m'] ?? null),
            memoryPercent: self::share(self::usedMemory($values), self::number($values['memory_total_mb'] ?? null)),
            diskPercent: self::share(self::number($values['disk_used_mb'] ?? null), self::number($values['disk_total_mb'] ?? null)),
            connections: self::integer($values['connected_clients'] ?? $values['threads_connected'] ?? null),
            opsPerSecond: self::number($values['instantaneous_ops_per_sec'] ?? null) ?? self::rateSince($previous, $queryTotal, $at),
            queryTotal: $queryTotal,
        );
    }

    /**
     * @return array<string, string|int|float|CarbonImmutable|null>
     */
    public function toRow(): array
    {
        return [
            'database_node_id' => $this->nodeId,
            'sampled_at' => $this->sampledAt,
            'cpu_percent' => $this->cpuPercent,
            'load_1m' => $this->load1m,
            'memory_percent' => $this->memoryPercent,
            'disk_percent' => $this->diskPercent,
            'connections' => $this->connections,
            'ops_per_second' => $this->opsPerSecond,
            'query_total' => $this->queryTotal,
        ];
    }

    /**
     * @param  array<string, string>  $values
     */
    private static function usedMemory(array $values): ?float
    {
        $total = self::number($values['memory_total_mb'] ?? null);
        $available = self::number($values['memory_available_mb'] ?? null);

        return $total === null || $available === null ? null : $total - $available;
    }

    private static function share(?float $part, ?float $whole): ?float
    {
        return $part === null || $whole === null || $whole <= 0 ? null : round($part * 100 / $whole, 1);
    }

    private static function rateSince(?NodeMetricSample $previous, ?int $total, CarbonImmutable $at): ?float
    {
        if ($previous?->query_total === null || $total === null || $total < $previous->query_total) {
            return null;
        }

        $seconds = $previous->sampled_at->diffInSeconds($at);

        return $seconds > 0 ? round(($total - $previous->query_total) / $seconds, 2) : null;
    }

    private static function number(?string $value): ?float
    {
        return $value !== null && is_numeric($value) ? (float) $value : null;
    }

    private static function integer(?string $value): ?int
    {
        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }
}
