<?php

declare(strict_types=1);

namespace App\Actions\Databases\Extensions;

use App\DTOs\Databases\PackageCandidate;
use App\DTOs\Databases\PackageFetch;
use App\Enums\Databases\PackageSource;
use App\Models\Database;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class ReadPackageCandidate
{
    public function handle(Database $database, string $packageId): PackageCandidate
    {
        $database->ensureIdle();
        $package = $database->extensionPackage($packageId);

        if ($package->source === PackageSource::Upload) {
            throw ValidationException::withMessages(['package' => 'Upload packages get new versions by uploading them.']);
        }

        return $database->driver()->extensions()->packageCandidate(new PackageFetch($package->source, $database->version_line, $package->request, $package->repository, $package->repositoryKey, 'check-'.strtolower(Str::ulid()->toBase32())));
    }
}
