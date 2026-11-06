<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

use App\Models\Server;
use Illuminate\Support\Number;

final readonly class NodeResources
{
    private const int FALLBACK_MEMORY_MB = 1024;

    private const int KERNEL_RESERVE_PERCENT = 8;

    public function __construct(
        public int $cpus,
        public int $memoryMb,
        public int $diskGb,
    ) {}

    public static function of(Server $server): self
    {
        return new self(
            cpus: max(1, $server->cpus ?? 1),
            memoryMb: max(self::FALLBACK_MEMORY_MB, $server->memory_mb ?? self::FALLBACK_MEMORY_MB),
            diskGb: max(1, $server->disk_gb ?? 10),
        );
    }

    public function memoryPercent(int $percent): int
    {
        return intdiv($this->memoryMb * $percent, 100);
    }

    public function memoryShare(int $divisor): int
    {
        return intdiv($this->memoryMb, $divisor);
    }

    public function cpuShare(int $divisor, int $min, int $max): int
    {
        return (int) Number::clamp(intdiv($this->cpus, $divisor), $min, $max);
    }

    public function hasMemoryOf(int $megabytes): bool
    {
        return $this->memoryMb >= intdiv($megabytes * (100 - self::KERNEL_RESERVE_PERCENT), 100);
    }
}
