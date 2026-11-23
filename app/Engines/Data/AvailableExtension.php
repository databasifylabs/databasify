<?php

declare(strict_types=1);

namespace App\Engines\Data;

final readonly class AvailableExtension
{
    public function __construct(
        public CatalogExtension $entry,
        public string $defaultVersion,
    ) {}
}
