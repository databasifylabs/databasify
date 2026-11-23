<?php

declare(strict_types=1);

namespace App\Actions\Databases\Extensions;

use App\DTOs\Databases\ExtensionPackage;
use App\DTOs\Databases\PackageFile;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Support\Databases\ExtensionChanges;
use RuntimeException;

final readonly class ApplyExtensionPackageRemoval
{
    public function __construct(
        private PruneExtensionPackageFiles $prune,
        private ExtensionChanges $changes,
    ) {}

    public function handle(Database $database, string $packageId): void
    {
        $package = $database->settings->package($packageId);

        if ($package === null) {
            $this->changes->end($database);
            $database->markReady();

            return;
        }

        $packages = $database->settings->packages;
        $remaining = array_values(array_filter($packages, fn (ExtensionPackage $candidate): bool => $candidate->id !== $packageId));
        $database->update(['settings' => $database->settings->withPackages($remaining)]);
        $extensions = $database->driver()->extensions();
        $extensions->allowInstalls();
        $inUse = collect($package->extensionNames())->first(fn (string $extension): bool => $extensions->usedIn($extension) !== []);

        if ($inUse !== null) {
            $database->update(['settings' => $database->settings->withPackages($packages)]);
            $database->driver()->extensions()->allowInstalls();

            throw new RuntimeException("{$inUse} was enabled before {$package->request} could be removed, so the package stays. Disable it first.");
        }

        $stillNeeded = collect($remaining)->flatMap(fn (ExtensionPackage $kept): array => $kept->files)->map(fn (PackageFile $file): string => $file->package);
        $packageNames = array_values(collect($package->files)->map(fn (PackageFile $file): string => $file->package)->unique()->diff($stillNeeded)->all());

        if ($packageNames !== []) {
            $database->nodesWithServers()->each(fn (DatabaseNode $node) => $node->driver()->extensions()->removePackages($packageNames));
        }

        $this->prune->handle($database);
        $this->changes->end($database);
        $database->markReady();
    }
}
