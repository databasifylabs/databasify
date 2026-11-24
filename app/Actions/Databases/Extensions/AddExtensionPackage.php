<?php

declare(strict_types=1);

namespace App\Actions\Databases\Extensions;

use App\DTOs\Databases\ExtensionChange;
use App\DTOs\Databases\PackageRequest;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\ExtensionChangeAction;
use App\Jobs\Databases\AddExtensionPackageJob;
use App\Models\Database;
use App\Support\Databases\ExtensionChanges;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final readonly class AddExtensionPackage
{
    public function __construct(private ExtensionChanges $changes) {}

    public function handle(Database $database, PackageRequest $request): void
    {
        try {
            $database->ensureAcceptsPackages();
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($request->uploads);

            throw $exception;
        }

        $database->markBusy(DatabaseStatus::Updating);
        $this->changes->start($database, new ExtensionChange(null, $request->label(), ExtensionChangeAction::Adding));
        dispatch(new AddExtensionPackageJob($database, $request, strtolower(Str::ulid()->toBase32())));
    }
}
