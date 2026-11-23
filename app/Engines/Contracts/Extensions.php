<?php

declare(strict_types=1);

namespace App\Engines\Contracts;

use App\DTOs\Databases\FetchedPackages;
use App\DTOs\Databases\PackageCandidate;
use App\DTOs\Databases\PackageFetch;
use App\DTOs\Databases\PackageRepository;
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

    public function officialRepository(): PackageRepository;

    public function packageDirectory(): string;

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

    public function architecture(): string;

    public function operatingSystem(): string;

    public function fetchPackages(PackageFetch $fetch): FetchedPackages;

    public function packageCandidate(PackageFetch $fetch): PackageCandidate;

    /**
     * @return list<string>
     */
    public function uploadedPackageNames(string $run): array;

    /**
     * @param  list<string>  $fileNames
     */
    public function installPackages(array $fileNames, bool $allowDowngrades = false): void;

    /**
     * @param  list<string>  $packageNames
     */
    public function removePackages(array $packageNames): void;

    public function discardFetch(string $run): void;

    /**
     * @param  list<string>  $fileNames
     */
    public function keepPackageFiles(array $fileNames): void;
}
