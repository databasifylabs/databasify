<?php

declare(strict_types=1);

namespace App\Actions\Databases\Extensions;

use App\DTOs\Databases\ExtensionChange;
use App\DTOs\Databases\ExtensionPackage;
use App\DTOs\Databases\PackageRequest;
use App\Enums\Databases\ExtensionChangeAction;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Support\Databases\ExtensionChanges;
use Illuminate\Support\Str;
use Throwable;

final readonly class ApplyExtensionPackage
{
    public function __construct(
        private MatchExistingExtensionPackage $match,
        private ApplyExtensionPackageUpdate $update,
        private FetchExtensionPackage $fetch,
        private InstallExtensionPackages $install,
        private ExtensionChanges $changes,
    ) {}

    public function handle(Database $database, PackageRequest $request, string $run): void
    {
        $existing = $this->match->handle($database, $request, $run);

        if ($existing !== null) {
            $this->changes->start($database, new ExtensionChange(null, $existing->request, ExtensionChangeAction::Updating));
            $this->update->handle($database, $existing->id, $request, $run);

            return;
        }

        $fetched = $this->fetch->handle($database, $request, $run);
        $package = new ExtensionPackage(Str::ulid()->toBase32(), $request->source, $request->label(), $request->repository, $request->repositoryKey, $fetched->packages, [...$fetched->files, ...$fetched->sharedFilesFrom($database->settings->packages)], $fetched->extensions);
        $touched = [];

        try {
            $database->nodesWithServers()->each(function (DatabaseNode $node) use ($database, $package, &$touched): void {
                $touched[] = $node;
                $this->install->handle($database, $node, [$package]);
            });
        } catch (Throwable $exception) {
            collect($touched)->each(fn (DatabaseNode $node) => rescue(fn () => $node->driver()->extensions()->removePackages($package->packageNames())));

            throw $exception;
        }

        $database->update(['settings' => $database->settings->withPackages([...$database->settings->packages, $package])]);
        $database->driver()->extensions()->allowInstalls();
        $this->changes->end($database);
        $database->markReady();
    }
}
