<?php

declare(strict_types=1);

namespace App\DTOs\Databases\Monitoring;

use App\Models\NodeMetricSample;

final readonly class NodeAverages
{
    public function __construct(
        public string $nodeId,
        public ?float $cpuPercent,
        public ?float $load1m,
        public ?float $memoryPercent,
        public ?float $diskPercent,
        public ?float $connections,
        public ?float $opsPerSecond,
    ) {}

    public static function fromSample(NodeMetricSample $sample): self
    {
        return new self(
            $sample->database_node_id,
            self::rounded($sample->cpu_percent),
            self::rounded($sample->load_1m),
            self::rounded($sample->memory_percent),
            self::rounded($sample->disk_percent),
            self::rounded($sample->connections),
            self::rounded($sample->ops_per_second),
        );
    }

    /**
     * @return array{cpu_percent: float|null, load_1m: float|null, memory_percent: float|null, disk_percent: float|null, connections: float|null, ops_per_second: float|null}
     */
    public function toArray(): array
    {
        return [
            'cpu_percent' => $this->cpuPercent,
            'load_1m' => $this->load1m,
            'memory_percent' => $this->memoryPercent,
            'disk_percent' => $this->diskPercent,
            'connections' => $this->connections,
            'ops_per_second' => $this->opsPerSecond,
        ];
    }

    private static function rounded(int|float|null $value): ?float
    {
        return $value === null ? null : round($value, 2);
    }
}
