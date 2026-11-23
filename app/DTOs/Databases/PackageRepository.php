<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

final readonly class PackageRepository
{
    public function __construct(
        public string $name,
        public string $host,
        public string $examplePackage,
    ) {}
}
