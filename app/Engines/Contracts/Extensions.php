<?php

declare(strict_types=1);

namespace App\Engines\Contracts;

use App\Engines\Data\AvailableExtension;
use App\Engines\Data\CatalogExtension;
use App\Engines\Data\InstalledExtension;

interface Extensions
{
    /**
     * @return list<AvailableExtension>
     */
    public function available(): array;

    /**
     * @return list<CatalogExtension>
     */
    public function catalog(): array;

    /**
     * @return list<InstalledExtension>
     */
    public function list(string $schema): array;

    public function enable(string $schema, string $name): void;

    public function update(string $schema, string $name): void;

    public function disable(string $schema, string $name): void;

    /**
     * @return list<string>
     */
    public function usedIn(string $name): array;

    public function allowInstalls(): void;
}
