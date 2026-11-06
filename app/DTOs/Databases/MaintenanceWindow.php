<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final readonly class MaintenanceWindow
{
    public function __construct(
        public int $day,
        public int $hour,
    ) {}

    public function nextStartAfter(CarbonInterface $moment): CarbonImmutable
    {
        $utc = CarbonImmutable::instance($moment)->utc();
        $start = $utc->startOfDay()->addDays(($this->day - $utc->dayOfWeek + 7) % 7)->setTime($this->hour, 0);

        return $start->greaterThan($utc) ? $start : $start->addWeek();
    }

    /**
     * @return array{day: int, hour: int, next_start_at: string}
     */
    public function toArray(CarbonInterface $now): array
    {
        return ['day' => $this->day, 'hour' => $this->hour, 'next_start_at' => $this->nextStartAfter($now)->toIso8601String()];
    }
}
