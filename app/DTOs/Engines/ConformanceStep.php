<?php

declare(strict_types=1);

namespace App\DTOs\Engines;

final readonly class ConformanceStep
{
    public function __construct(
        public string $name,
        public ?string $failure,
    ) {}

    public function passed(): bool
    {
        return $this->failure === null;
    }
}
