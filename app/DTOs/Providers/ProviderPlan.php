<?php

declare(strict_types=1);

namespace App\DTOs\Providers;

final readonly class ProviderPlan
{
    public function __construct(
        public string $id,
        public string $family,
        public int $cpus,
        public int $memoryMb,
        public int $diskGb,
        public ?float $monthlyPrice,
    ) {}

    /**
     * @return array{id: string, family: string, cpus: int, memory_mb: int, disk_gb: int, monthly_price: ?float}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'family' => $this->family, 'cpus' => $this->cpus, 'memory_mb' => $this->memoryMb, 'disk_gb' => $this->diskGb, 'monthly_price' => $this->monthlyPrice];
    }
}
