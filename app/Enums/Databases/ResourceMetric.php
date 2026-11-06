<?php

declare(strict_types=1);

namespace App\Enums\Databases;

enum ResourceMetric: string
{
    case Cpu = 'cpu_percent';
    case Memory = 'memory_percent';
    case Disk = 'disk_percent';

    public function label(): string
    {
        return match ($this) {
            self::Cpu => 'CPU',
            self::Memory => 'Memory',
            self::Disk => 'Disk',
        };
    }
}
