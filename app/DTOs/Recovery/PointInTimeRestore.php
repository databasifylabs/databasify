<?php

declare(strict_types=1);

namespace App\DTOs\Recovery;

use Carbon\CarbonImmutable;

final readonly class PointInTimeRestore
{
    public function __construct(
        public string $name,
        public string $serverId,
        public CarbonImmutable $target,
    ) {}
}
