<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Postgres;

use App\Engines\Contracts\Extensions;
use App\Engines\Data\AvailableExtension;
use App\Engines\Data\CatalogExtension;
use App\Engines\Data\InstalledExtension;
use App\Engines\Payload;
use App\Support\Images\ImageCache;

final readonly class PostgresExtensions implements Extensions
{
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

        return array_values(collect($entries)
            ->filter(fn (CatalogExtension $entry): bool => isset($versions[$entry->name]))
            ->map(fn (CatalogExtension $entry): AvailableExtension => new AvailableExtension($entry, $versions[$entry->name] ?? ''))
            ->all());
    }

    public function catalog(): array
    {
        return PostgresExtensionCatalog::entries();
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
}
