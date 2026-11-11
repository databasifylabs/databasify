<?php

declare(strict_types=1);

namespace App\DTOs\Images;

final readonly class HostBinaryImage
{
    public function __construct(
        public string $repository,
        public string $version,
        public string $digest,
    ) {}

    public function pinnedReference(): string
    {
        return "{$this->repository}@{$this->digest}";
    }
}
