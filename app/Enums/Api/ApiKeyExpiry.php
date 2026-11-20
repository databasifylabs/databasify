<?php

declare(strict_types=1);

namespace App\Enums\Api;

use Carbon\CarbonInterval;

enum ApiKeyExpiry: string
{
    case Days30 = '30';
    case Days90 = '90';
    case Year = '365';
    case Never = 'never';

    public function interval(): CarbonInterval
    {
        return match ($this) {
            self::Days30 => CarbonInterval::days(30),
            self::Days90 => CarbonInterval::days(90),
            self::Year => CarbonInterval::year(),
            self::Never => CarbonInterval::years(100),
        };
    }
}
