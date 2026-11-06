<?php

declare(strict_types=1);

namespace App\DTOs\Acme;

final readonly class DnsChallenge
{
    public function __construct(
        public string $name,
        public string $value,
    ) {}
}
