<?php

declare(strict_types=1);

namespace App\Actions\Databases\Extensions;

use App\DTOs\Databases\ExtensionChange;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\ExtensionChangeAction;
use App\Jobs\Databases\RemoveExtensionPackageJob;
use App\Models\Database;
use App\Support\Databases\ExtensionChanges;
use Illuminate\Validation\ValidationException;

final readonly class RemoveExtensionPackage
{
    public function __construct(private ExtensionChanges $changes) {}

    public function handle(Database $database, string $packageId): void
    {
        $database->ensureIdle();
        $package = $database->extensionPackage($packageId);

        $inUse = collect($package->extensionNames())
            ->map(fn (string $extension): array => [$extension, $database->driver()->extensions()->usedIn($extension)[0] ?? null])
            ->first(fn (array $use): bool => $use[1] !== null);

        if ($inUse !== null) {
            throw ValidationException::withMessages(['package' => "{$inUse[0]} is enabled in {$inUse[1]}, so {$package->request} can't be removed. Disable it first."]);
        }

        $database->markBusy(DatabaseStatus::Updating);
        $this->changes->start($database, new ExtensionChange(null, $package->request, ExtensionChangeAction::Removing));
        dispatch(new RemoveExtensionPackageJob($database, $packageId));
    }
}
