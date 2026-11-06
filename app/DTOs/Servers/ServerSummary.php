<?php

declare(strict_types=1);

namespace App\DTOs\Servers;

final readonly class ServerSummary
{
    public function __construct(
        public int $total,
        public int $inUse,
        public int $available,
    ) {}

    /**
     * @return array{total: int, in_use: int, available: int}
     */
    public function toArray(): array
    {
        return ['total' => $this->total, 'in_use' => $this->inUse, 'available' => $this->available];
    }
}
