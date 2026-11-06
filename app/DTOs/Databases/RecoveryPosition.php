<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

final readonly class RecoveryPosition
{
    public function __construct(
        public string $nodeId,
        public ?int $position,
    ) {}
}
