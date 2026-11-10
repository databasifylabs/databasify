<?php

declare(strict_types=1);

namespace App\Engines\Data;

final readonly class ServiceRecord
{
    public function __construct(
        public string $prefix,
        public string $options,
    ) {}
}
