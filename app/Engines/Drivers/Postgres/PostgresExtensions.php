<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Postgres;

use App\DTOs\Databases\ExtensionPackage;
use App\DTOs\Databases\FetchedPackages;
use App\DTOs\Databases\PackageCandidate;
use App\DTOs\Databases\PackageExtension;
use App\DTOs\Databases\PackageFetch;
use App\DTOs\Databases\PackageRepository;
use App\Engines\Contracts\Extensions;
use App\Engines\Data\AvailableExtension;
use App\Engines\Data\CatalogExtension;
use App\Engines\Data\InstalledExtension;
use App\Engines\Payload;
use App\Enums\Engines\ExtensionGroup;
use App\Support\Images\ImageCache;

final readonly class PostgresExtensions implements Extensions
{
    private const int FETCH_TIMEOUT = 1200;

    private const int INSTALL_TIMEOUT = 600;

    private const string PACKAGE_DIRECTORY = '/srv/postgres/packages';

    public function __construct(
        private PostgresDriver $driver,
        private ImageCache $cache,
    ) {}

    public function available(): array
    {
        $entries = $this->catalog();
        $versions = $this->cache->extensionsOf(
            $this->driver->node->database->image()->digest,
            fn (): array => collect($this->driver->queryJson('engines.postgres.extensions.available', ['names' => array_map(fn (CatalogExtension $entry): string => $entry->name, $entries)])->objects('extensions'))
                ->mapWithKeys(fn (Payload $extension): array => [$extension->string('name') => $extension->string('defaultVersion')])
                ->all(),
        );

        return array_values(collect($this->driver->node->database->settings->packages)
            ->flatMap(fn (ExtensionPackage $package): array => array_map(
                fn (PackageExtension $extension): AvailableExtension => new AvailableExtension(new CatalogExtension($extension->name, $extension->name, "From {$package->request}", ExtensionGroup::Custom), $extension->defaultVersion),
                $package->extensions,
            ))
            ->merge(collect($entries)
                ->filter(fn (CatalogExtension $entry): bool => isset($versions[$entry->name]))
                ->map(fn (CatalogExtension $entry): AvailableExtension => new AvailableExtension($entry, $versions[$entry->name] ?? '')))
            ->all());
    }

    public function catalog(): array
    {
        return PostgresExtensionCatalog::entries();
    }

    public function officialRepository(): PackageRepository
    {
        return new PackageRepository('PGDG', 'apt.postgresql.org', "postgresql-{$this->driver->node->database->version_line}-http");
    }

    public function packageDirectory(): string
    {
        return self::PACKAGE_DIRECTORY;
    }

    public function packageTrialScript(?PackageFetch $fetch, array $fileNames): string
    {
        return $this->driver->script('engines.postgres.extensions.packages.prepare', ['fetch' => $fetch, 'directory' => self::PACKAGE_DIRECTORY, 'fileNames' => $fileNames]);
    }

    public function list(string $schema): array
    {
        return array_map(InstalledExtension::fromPayload(...), $this->driver->queryJson('engines.postgres.extensions.list', ['schema' => $schema])->objects('extensions'));
    }

    public function enable(string $schema, string $name): void
    {
        $this->driver->query('engines.postgres.extensions.enable', ['schema' => $schema, 'name' => $name]);
    }

    public function update(string $schema, string $name): void
    {
        $this->driver->query('engines.postgres.extensions.update', ['schema' => $schema, 'name' => $name]);
    }

    public function disable(string $schema, string $name): void
    {
        $this->driver->query('engines.postgres.extensions.disable', ['schema' => $schema, 'name' => $name]);
    }

    public function usedIn(string $name): array
    {
        return $this->driver->read('engines.postgres.extensions.used-in', ['name' => $name])->strings('schemas');
    }

    public function allowInstalls(): void
    {
        $this->driver->query('engines.postgres.extensions.allowlist', ['allowlist' => PostgresExtensionCatalog::allowlistFor($this->driver->node->database)]);
    }

    public function allowlistModuleInstalled(): bool
    {
        return $this->driver->read('engines.postgres.extensions.allowlist-module', ['line' => $this->driver->node->database->version_line])->bool('installed');
    }

    public function architecture(): string
    {
        return $this->driver->read('engines.postgres.extensions.packages.architecture')->string('architecture');
    }

    public function operatingSystem(): string
    {
        return $this->driver->read('engines.postgres.extensions.packages.os')->string('name');
    }

    public function fetchPackages(PackageFetch $fetch): FetchedPackages
    {
        return FetchedPackages::fromPayload($this->driver->read('engines.postgres.extensions.packages.fetch', ['fetch' => $fetch, 'directory' => self::PACKAGE_DIRECTORY], timeout: self::FETCH_TIMEOUT));
    }

    public function packageCandidate(PackageFetch $fetch): PackageCandidate
    {
        return PackageCandidate::fromPayload($this->driver->read('engines.postgres.extensions.packages.candidate', ['fetch' => $fetch]));
    }

    public function uploadedPackageNames(string $run): array
    {
        return $this->driver->read('engines.postgres.extensions.packages.names', ['run' => $run, 'directory' => self::PACKAGE_DIRECTORY])->strings('names');
    }

    public function installPackages(array $fileNames, bool $allowDowngrades = false): void
    {
        $this->driver->run('engines.postgres.extensions.packages.install', ['fileNames' => $fileNames, 'directory' => self::PACKAGE_DIRECTORY, 'allowDowngrades' => $allowDowngrades], timeout: self::INSTALL_TIMEOUT);
    }

    public function removePackages(array $packageNames): void
    {
        $this->driver->run('engines.postgres.extensions.packages.remove', ['packageNames' => $packageNames], timeout: self::INSTALL_TIMEOUT);
    }

    public function discardFetch(string $run): void
    {
        $this->driver->run('engines.postgres.extensions.packages.discard', ['run' => $run, 'directory' => self::PACKAGE_DIRECTORY]);
    }

    public function keepPackageFiles(array $fileNames): void
    {
        $this->driver->run('engines.postgres.extensions.packages.keep', ['fileNames' => $fileNames, 'directory' => self::PACKAGE_DIRECTORY]);
    }
}
