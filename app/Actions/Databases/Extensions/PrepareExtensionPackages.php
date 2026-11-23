<?php

declare(strict_types=1);

namespace App\Actions\Databases\Extensions;

use App\Contracts\Ssh\ServerConnection;
use App\DTOs\Databases\EngineImage;
use App\DTOs\Databases\ExtensionPackage;
use App\DTOs\Databases\FetchedPackages;
use App\DTOs\Databases\PackageFetch;
use App\DTOs\Databases\PackageFile;
use App\DTOs\Databases\PreparedPackages;
use App\Engines\Scripts\ScriptResult;
use App\Engines\Scripts\Shell;
use App\Enums\Databases\PackageSource;
use App\Events\DatabaseAlertRaised;
use App\Models\Database;
use App\Support\Databases\UpgradeCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class PrepareExtensionPackages
{
    public function __construct(
        private ServerConnection $connection,
        private SaveExtensionPackageFiles $save,
        private UpgradeCache $cache,
    ) {}

    public function handle(Database $database, EngineImage $image): void
    {
        $primary = $database->primaryOrSeed();
        $extensions = $primary->driver()->extensions();
        $directory = $extensions->packageDirectory();
        $source = $database->settings->packages;

        try {
            $prepared = array_values(collect($source)
                ->map(function (ExtensionPackage $package) use ($database, $primary, $extensions, $directory, $image): ?ExtensionPackage {
                    $refetch = $package->source !== PackageSource::Upload;
                    $run = 'prepare-'.strtolower(Str::ulid()->toBase32());
                    $script = $extensions->packageTrialScript(
                        $refetch ? new PackageFetch($package->source, $database->version_line, $package->request, $package->repository, $package->repositoryKey, $run) : null,
                        $refetch ? [] : array_map(fn (PackageFile $file): string => $file->hostName(), $package->filesFor($primary->architecture ?? $extensions->architecture())),
                    );
                    $output = '';

                    try {
                        $exit = $this->connection->run(
                            $primary->server,
                            view('ssh.databases.packages.prepare', ['sh' => new Shell, 'reference' => $image->reference(), 'directory' => $directory, 'script' => $script])->render(),
                            function (string $chunk) use (&$output): void {
                                $output .= $chunk;
                            },
                        );

                        if ($exit !== 0) {
                            throw new RuntimeException("{$package->request} can't be installed on {$image->release}: ".Str::afterLast(trim($output), "\n"));
                        }

                        if (! $refetch) {
                            return $package;
                        }

                        $fetched = FetchedPackages::fromPayload((new ScriptResult('extensions.packages.prepare', $output))->json());

                        if ($fetched->alreadyInstalled) {
                            return null;
                        }

                        if ($fetched->refusal !== null) {
                            throw new RuntimeException("{$package->request} can't be installed on {$image->release}: {$fetched->refusal}");
                        }

                        $this->save->handle($primary, $run, $fetched);

                        return new ExtensionPackage($package->id, $package->source, $package->request, $package->repository, $package->repositoryKey, $fetched->packages, $fetched->files, $fetched->extensions);
                    } finally {
                        rescue(fn () => $extensions->discardFetch($run), report: false);
                    }
                })
                ->filter()
                ->all());
        } catch (RuntimeException $failure) {
            $this->cache->recordPreparationFailure($database, $image->digest);
            event(new DatabaseAlertRaised($database, "{$database->name} stays on {$database->image->release}", $failure->getMessage()));

            return;
        }

        DB::transaction(function () use ($database, $image, $source, $prepared): void {
            $locked = $database->refreshForUpdate();
            $sourceFingerprints = ExtensionPackage::fingerprintsOf($source);

            if (ExtensionPackage::fingerprintsOf($locked->settings->packages) !== $sourceFingerprints) {
                return;
            }

            $locked->update(['settings' => $locked->settings->withNextPackages(new PreparedPackages($image->digest, $prepared, $sourceFingerprints))]);
            $locked->recordAvailableImage($image);
        });
    }
}
