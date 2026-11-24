<?php

declare(strict_types=1);

namespace App\Actions\Databases\Extensions;

use App\Contracts\Ssh\ServerConnection;
use App\DTOs\Databases\FetchedPackages;
use App\DTOs\Databases\PackageFile;
use App\Models\DatabaseNode;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final readonly class SaveExtensionPackageFiles
{
    public const string DIRECTORY = 'extension-packages';

    public function __construct(private ServerConnection $connection) {}

    public function handle(DatabaseNode $node, string $run, FetchedPackages $fetched): void
    {
        $disk = Storage::disk('local');

        collect($fetched->files)
            ->reject(fn (PackageFile $file): bool => $disk->exists(self::DIRECTORY."/{$file->hostName()}"))
            ->each(function (PackageFile $file) use ($node, $run, $disk): void {
                $download = tempnam(sys_get_temp_dir(), 'pkg') ?: throw new RuntimeException('No temporary file for the download.');

                try {
                    $this->connection->getFile($node->server, "{$node->driver()->extensions()->packageDirectory()}/incoming/{$run}/{$file->fileName}", $download);

                    if (hash_file('sha256', $download) !== $file->sha256) {
                        throw new RuntimeException("{$file->fileName} changed in transit.");
                    }

                    $disk->putFileAs(self::DIRECTORY, $download, $file->hostName());
                } finally {
                    File::delete($download);
                }
            });
    }
}
