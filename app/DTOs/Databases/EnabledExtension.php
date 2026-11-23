<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

final readonly class EnabledExtension
{
    public function __construct(
        public string $schema,
        public string $name,
        public string $version,
    ) {}
}
