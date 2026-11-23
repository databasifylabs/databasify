<?php

declare(strict_types=1);

namespace App\Actions\Databases\Extensions;

use App\Contracts\Ssh\ServerConnection;
use App\DTOs\Databases\ExtensionPackage;
use App\DTOs\Databases\PackageFile;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Ssh\Ssh;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final readonly class InstallExtensionPackages
{
    public function __construct(private ServerConnection $connection) {}

    /**
     * @param  list<ExtensionPackage>  $packages
     */
    public function handle(Database $database, DatabaseNode $node, array $packages, bool $allowDowngrades = false): void
    {
        $extensions = $node->driver()->extensions();
        $architecture = $node->architecture ?? $extensions->architecture();
        $node->update(['architecture' => $architecture]);
        $database->ensurePackagesRunOn($packages, $architecture, $node->server->name);
        $files = collect($packages)->flatMap(fn (ExtensionPackage $package): array => array_map(fn (PackageFile $file): array => [$package, $file], $package->filesFor($architecture)))
            ->unique(fn (array $pair): string => $pair[1]->sha256);

        if ($files->isEmpty()) {
            return;
        }

        $directory = $extensions->packageDirectory();
        $disk = Storage::disk('local');
        $files
            ->reject(fn (array $pair): bool => $disk->exists(SaveExtensionPackageFiles::DIRECTORY."/{$pair[1]->hostName()}"))
            ->each(function (array $pair) use ($database, $directory, $disk): void {
                [$package, $file] = $pair;
                $recovered = $database->nodesWithServers()->contains(function (DatabaseNode $peer) use ($file, $directory, $disk): bool {
                    $download = tempnam(sys_get_temp_dir(), 'pkg') ?: throw new RuntimeException('No temporary file for the download.');

                    try {
                        $this->connection->getFile($peer->server, "{$directory}/{$file->hostName()}", $download);

                        return hash_file('sha256', $download) === $file->sha256
                            && $disk->putFileAs(SaveExtensionPackageFiles::DIRECTORY, $download, $file->hostName()) !== false;
                    } catch (Throwable) {
                        return false;
                    } finally {
                        File::delete($download);
                    }
                });

                if (! $recovered) {
                    throw ValidationException::withMessages(['package' => "The app no longer has {$file->fileName} of {$package->request}. Remove the package and add it again."]);
                }
            });
        $fileNames = array_values($files->map(fn (array $pair): string => $pair[1]->hostName())->all());
        $missing = $node->ssh()->exec('ssh.databases.packages.missing', ['directory' => $directory, 'fileNames' => $fileNames], timeout: Ssh::TIMEOUT);

        collect(explode("\n", trim($missing)))
            ->intersect($fileNames)
            ->each(fn (string $fileName) => $this->connection->putFile($node->server, $disk->path(SaveExtensionPackageFiles::DIRECTORY."/{$fileName}"), "{$directory}/{$fileName}"));

        $extensions->installPackages($fileNames, $allowDowngrades);
    }
}
