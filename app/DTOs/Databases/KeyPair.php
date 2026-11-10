<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

final readonly class KeyPair
{
    public function __construct(
        public string $certificate,
        public string $key,
    ) {}
}
