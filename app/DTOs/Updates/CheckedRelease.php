<?php

declare(strict_types=1);

namespace App\DTOs\Updates;

use Carbon\CarbonImmutable;

final readonly class CheckedRelease
{
    public function __construct(public Release $release, public CarbonImmutable $checkedAt) {}
}
