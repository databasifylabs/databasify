<?php

declare(strict_types=1);

namespace App\Actions\Databases\Extensions;

use App\DTOs\Databases\EnabledExtension;
use App\DTOs\Databases\ExtensionPackage;
use App\DTOs\Databases\PackageRequest;
use App\Engines\Data\InstalledExtension;
use App\Enums\Databases\PackageSource;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Support\Databases\ExtensionCache;
use App\Support\Databases\ExtensionChanges;
use RuntimeException;
use Throwable;

final readonly class ApplyExtensionPackageUpdate
{
    public function __construct(
        private FetchExtensionPackage $fetch,
        private InstallExtensionPackages $install,
        private PruneExtensionPackageFiles $prune,
        private ExtensionCache $cache,
        private ExtensionChanges $changes,
    ) {}

    public function handle(Database $database, string $packageId, PackageRequest $request, string $run): void
    {
        $old = $database->settings->package($packageId) ?? throw new RuntimeException("{$database->name} no longer has this extension package.");
        $extensions = $database->driver()->extensions();
        $names = $old->extensionNames();
        $enabled = array_values(collect($names)
            ->flatMap(fn (string $name): array => $extensions->usedIn($name))
            ->unique()
            ->flatMap(fn (string $schema): array => collect($extensions->list($schema))
                ->filter(fn (InstalledExtension $installed): bool => in_array($installed->name, $names, true))
                ->map(fn (InstalledExtension $installed): EnabledExtension => new EnabledExtension($schema, $installed->name, $installed->version))
                ->all())
            ->all());
        $fetched = $this->fetch->handle($database, $request, $run, $old, $enabled);

        if ($fetched->alreadyInstalled) {
            if ($request->source === PackageSource::Upload) {
                throw new RuntimeException($fetched->refusal ?? "{$old->request} is already installed.");
            }

            $this->changes->end($database);
            $database->markReady();

            return;
        }

        $new = $old->updatedBy($fetched, $request->source === PackageSource::Upload ? $request->label() : $old->request, array_values(array_filter($database->settings->packages, fn (ExtensionPackage $package): bool => $package->id !== $old->id)));
        $primary = $database->primaryOrSeed();
        $touched = [];

        try {
            $database->nodesWithServers()
                ->sortBy(fn (DatabaseNode $node): bool => $node->is($primary))
                ->each(function (DatabaseNode $node) use ($database, $new, &$touched): void {
                    $touched[] = $node;
                    $this->install->handle($database, $node, [$new]);
                });
        } catch (Throwable $exception) {
            $added = array_values(array_diff($new->packageNames(), $old->packageNames()));
            $stuck = collect($touched)
                ->reject(fn (DatabaseNode $node): bool => rescue(function () use ($database, $node, $old, $added): bool {
                    $this->install->handle($database, $node, [$old], allowDowngrades: true);

                    if ($added !== []) {
                        $node->driver()->extensions()->removePackages($added);
                    }

                    return true;
                }, false))
                ->map(fn (DatabaseNode $node): string => $node->server->name);

            if ($stuck->isNotEmpty()) {
                throw new RuntimeException("{$exception->getMessage()} Going back to the old version failed on {$stuck->implode(', ')}.", previous: $exception);
            }

            throw $exception;
        }

        $database->update(['settings' => $database->settings
            ->withPackages(array_map(fn (ExtensionPackage $package): ExtensionPackage => $package->id === $old->id ? $new : $package, $database->settings->packages))]);
        $database->driver()->extensions()->allowInstalls();
        collect($enabled)->each(fn (EnabledExtension $extension) => $this->cache->forget($database, $extension->schema));
        $this->prune->handle($database);
        $this->changes->end($database);
        $database->markReady();
    }
}
