<?php

declare(strict_types=1);

namespace App\Actions\Databases\Extensions;

use App\DTOs\Databases\EnabledExtension;
use App\DTOs\Databases\ExtensionPackage;
use App\DTOs\Databases\FetchedPackages;
use App\DTOs\Databases\PackageExtension;
use App\DTOs\Databases\PackageFetch;
use App\DTOs\Databases\PackageRequest;
use App\Engines\Data\CatalogExtension;
use App\Models\Database;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final readonly class FetchExtensionPackage
{
    public function __construct(private SaveExtensionPackageFiles $save) {}

    /**
     * @param  list<EnabledExtension>  $enabled
     */
    public function handle(Database $database, PackageRequest $request, string $run, ?ExtensionPackage $replacing = null, array $enabled = []): FetchedPackages
    {
        $primary = $database->primaryOrSeed();

        try {
            $dependent = $replacing?->dependentIn($database->settings->packages);

            if ($dependent !== null) {
                throw new RuntimeException("{$dependent->request} depends on {$replacing->request}. Remove {$dependent->request} first.");
            }

            $fetched = $primary->driver()->extensions()->fetchPackages(new PackageFetch($request->source, $database->version_line, $request->package, $request->repository, $request->repositoryKey, $run, $replacing?->packageNames() ?? [], $enabled));

            if ($fetched->alreadyInstalled && $replacing !== null) {
                return $fetched;
            }

            if ($fetched->refusal !== null) {
                throw new RuntimeException($fetched->refusal);
            }

            $owners = collect($primary->driver()->extensions()->catalog())
                ->mapWithKeys(fn (CatalogExtension $entry): array => [$entry->name => 'the image'])
                ->merge(collect($database->settings->packages)
                    ->reject(fn (ExtensionPackage $package): bool => $package->id === $replacing?->id)
                    ->flatMap(fn (ExtensionPackage $package): array => array_fill_keys($package->extensionNames(), $package->request)));
            $clash = collect($fetched->extensions)->first(fn (PackageExtension $extension): bool => $owners->has($extension->name));

            if ($clash !== null) {
                throw new RuntimeException("{$clash->name} is already provided by {$owners->get($clash->name)}.");
            }

            $this->save->handle($primary, $run, $fetched);

            return $fetched;
        } finally {
            rescue(fn () => $primary->driver()->extensions()->discardFetch($run), report: false);
            Storage::disk('local')->delete($request->uploads);
        }
    }
}
