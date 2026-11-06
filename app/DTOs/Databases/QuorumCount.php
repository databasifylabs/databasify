<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

final readonly class QuorumCount
{
    public function __construct(
        public int $total,
        public int $online,
    ) {}

    public function hasMajority(): bool
    {
        return $this->total > 0 && $this->online * 2 > $this->total;
    }
}
