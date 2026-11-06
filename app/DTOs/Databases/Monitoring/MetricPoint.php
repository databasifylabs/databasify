<?php

declare(strict_types=1);

namespace App\DTOs\Databases\Monitoring;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final readonly class MetricPoint
{
    /**
     * @param  Collection<int, NodeAverages>  $averages
     */
    public function __construct(
        public CarbonInterface $startsAt,
        public Collection $averages,
    ) {}

    /**
     * @return array{t: string, values: array<string, array{cpu_percent: float|null, load_1m: float|null, memory_percent: float|null, disk_percent: float|null, connections: float|null, ops_per_second: float|null}>}
     */
    public function toArray(): array
    {
        return [
            't' => $this->startsAt->toIso8601String(),
            'values' => $this->averages->mapWithKeys(fn (NodeAverages $averages): array => [$averages->nodeId => $averages->toArray()])->all(),
        ];
    }
}
