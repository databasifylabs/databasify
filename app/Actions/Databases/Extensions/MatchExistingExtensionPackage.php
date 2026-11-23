<?php

declare(strict_types=1);

namespace App\Actions\Databases\Extensions;

use App\Contracts\Ssh\ServerConnection;
use App\DTOs\Databases\ExtensionPackage;
use App\DTOs\Databases\PackageRequest;
use App\Enums\Databases\PackageSource;
use App\Models\Database;
use Illuminate\Support\Facades\Storage;
use Throwable;

final readonly class MatchExistingExtensionPackage
{
    public function __construct(private ServerConnection $connection) {}

    public function handle(Database $database, PackageRequest $request, string $run): ?ExtensionPackage
    {
        $primary = $database->primaryOrSeed();

        try {
            collect($request->uploads)->each(fn (string $upload) => $this->connection->putFile($primary->server, Storage::disk('local')->path($upload), "{$primary->driver()->extensions()->packageDirectory()}/incoming/{$run}/".basename($upload)));
            $names = $request->source === PackageSource::Upload ? $primary->driver()->extensions()->uploadedPackageNames($run) : [$request->package];
        } catch (Throwable $failure) {
            rescue(fn () => $primary->driver()->extensions()->discardFetch($run), report: false);
            Storage::disk('local')->delete($request->uploads);

            throw $failure;
        }

        return collect($database->settings->packages)->first(fn (ExtensionPackage $package): bool => $package->source === $request->source
            && array_intersect($names, $package->packageNames()) !== []);
    }
}
