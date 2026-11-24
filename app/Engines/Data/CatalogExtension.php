<?php

declare(strict_types=1);

namespace App\Engines\Data;

use App\Enums\Engines\ExtensionGroup;

final readonly class CatalogExtension
{
    public function __construct(
        public string $name,
        public string $label,
        public string $description,
        public ExtensionGroup $group,
        public ?string $library = null,
        public bool $singleSchema = false,
    ) {}

    public function needsRestart(): bool
    {
        return $this->library !== null;
    }
}
