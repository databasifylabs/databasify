<?php

declare(strict_types=1);

namespace App\Actions\Databases\Extensions;

use App\DTOs\Databases\PackageFile;
use App\Models\Database;
use App\Models\DatabaseNode;
use Illuminate\Support\Facades\Storage;

final readonly class PruneExtensionPackageFiles
{
    public function handle(Database $database): void
    {
        $kept = array_map(fn (PackageFile $file): string => $file->hostName(), $database->settings->packageFiles());
        $database->nodesWithServers()->each(fn (DatabaseNode $node) => $node->driver()->extensions()->keepPackageFiles($kept));

        $referenced = array_flip(Database::query()->withoutProject()->select(['id', 'settings'])->lazyById()
            ->flatMap(fn (Database $other): array => $other->settings->packageFiles())
            ->map(fn (PackageFile $file): string => $file->hostName())
            ->all());
        $disk = Storage::disk('local');
        $disk->delete(collect($disk->files(SaveExtensionPackageFiles::DIRECTORY))
            ->reject(fn (string $path): bool => isset($referenced[basename($path)]))
            ->all());
    }
}
