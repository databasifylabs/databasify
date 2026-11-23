<?php

declare(strict_types=1);

namespace App\Actions\Databases\Extensions;

use App\DTOs\Databases\ExtensionChange;
use App\DTOs\Databases\PackageRequest;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\ExtensionChangeAction;
use App\Enums\Databases\PackageSource;
use App\Jobs\Databases\UpdateExtensionPackageJob;
use App\Models\Database;
use App\Support\Databases\ExtensionChanges;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class UpdateExtensionPackage
{
    public function __construct(private ExtensionChanges $changes) {}

    public function handle(Database $database, string $packageId): void
    {
        $database->ensureAcceptsPackages();
        $package = $database->extensionPackage($packageId);

        if ($package->source === PackageSource::Upload) {
            throw ValidationException::withMessages(['package' => "Upload the new files with Add package; they replace {$package->request}."]);
        }

        $database->markBusy(DatabaseStatus::Updating);
        $this->changes->start($database, new ExtensionChange(null, $package->request, ExtensionChangeAction::Updating));
        dispatch(new UpdateExtensionPackageJob($database, $packageId, new PackageRequest($package->source, $package->request, $package->repository, $package->repositoryKey, []), strtolower(Str::ulid()->toBase32())));
    }
}
