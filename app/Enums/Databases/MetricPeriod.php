<?php

declare(strict_types=1);

namespace App\Enums\Databases;

enum MetricPeriod: string
{
    case Hour = '1h';
    case SixHours = '6h';
    case Day = '24h';
    case Week = '7d';

    public function seconds(): int
    {
        return match ($this) {
            self::Hour => 3600,
            self::SixHours => 21600,
            self::Day => 86400,
            self::Week => 604800,
        };
    }

    public function bucketSeconds(): int
    {
        return match ($this) {
            self::Hour => 60,
            self::SixHours => 300,
            self::Day => 900,
            self::Week => 7200,
        };
    }
}
