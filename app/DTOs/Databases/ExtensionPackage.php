<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

use App\Engines\Payload;
use App\Enums\Databases\PackageSource;
use Illuminate\Support\Str;

final readonly class ExtensionPackage
{
    /**
     * @param  list<string>  $packages
     * @param  list<PackageFile>  $files
     * @param  list<PackageExtension>  $extensions
     */
    public function __construct(
        public string $id,
        public PackageSource $source,
        public string $request,
        public ?string $repository,
        public ?string $repositoryKey,
        public array $packages,
        public array $files,
        public array $extensions,
    ) {}

    public static function fromPayload(Payload $payload): self
    {
        return new self(
            id: $payload->string('id'),
            source: PackageSource::from($payload->string('source')),
            request: $payload->string('request'),
            repository: $payload->nullableString('repository'),
            repositoryKey: $payload->nullableString('repositoryKey'),
            packages: $payload->strings('packages'),
            files: array_map(PackageFile::fromPayload(...), $payload->objects('files')),
            extensions: array_map(PackageExtension::fromPayload(...), $payload->objects('extensions')),
        );
    }

    /**
     * @return array{id: string, source: string, request: string, repository: string|null, repositoryKey: string|null, packages: list<string>, files: list<array{sha256: string, fileName: string, architecture: string, package: string}>, extensions: list<array{name: string, defaultVersion: string}>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source->value,
            'request' => $this->request,
            'repository' => $this->repository,
            'repositoryKey' => $this->repositoryKey,
            'packages' => $this->packages,
            'files' => array_map(fn (PackageFile $file): array => $file->toArray(), $this->files),
            'extensions' => array_map(fn (PackageExtension $extension): array => $extension->toArray(), $this->extensions),
        ];
    }

    /**
     * @param  list<self>  $packages
     * @return list<string>
     */
    public static function fingerprintsOf(array $packages): array
    {
        return array_values(collect($packages)->map(fn (self $package): string => "{$package->id}:".implode(',', $package->packages))->sort()->all());
    }

    /**
     * @param  list<self>  $packages
     * @return list<string>
     */
    public static function extensionNamesOf(array $packages): array
    {
        return array_values(collect($packages)->flatMap(fn (self $package): array => $package->extensionNames())->all());
    }

    /**
     * @param  list<self>  $packages
     * @return list<string>
     */
    public static function architecturesOf(array $packages): array
    {
        return array_values(collect($packages)
            ->flatMap(fn (self $package): array => $package->files)
            ->map(fn (PackageFile $file): string => $file->architecture)
            ->reject(fn (string $architecture): bool => $architecture === 'all')
            ->unique()
            ->sort()
            ->all());
    }

    /**
     * @return list<PackageFile>
     */
    public function filesFor(string $architecture): array
    {
        return array_values(array_filter($this->files, fn (PackageFile $file): bool => in_array($file->architecture, [$architecture, 'all'], true)));
    }

    /**
     * @param  list<self>  $packages
     */
    public function dependentIn(array $packages): ?self
    {
        return collect($packages)->first(fn (self $other): bool => $other->id !== $this->id
            && collect($other->files)->contains(fn (PackageFile $file): bool => in_array($file->package, $this->packageNames(), true)));
    }

    /**
     * @param  list<self>  $others
     */
    public function updatedBy(FetchedPackages $fetched, string $request, array $others): self
    {
        $fetchedNames = array_map(fn (string $pinned): string => Str::before($pinned, '='), $fetched->packages);
        $unchangedPins = array_values(array_filter($this->packages, fn (string $pinned): bool => ! in_array(Str::before($pinned, '='), $fetchedNames, true)));
        $unchangedFiles = array_filter($this->files, fn (PackageFile $file): bool => ! in_array($file->package, $fetchedNames, true));
        $files = collect([...$fetched->files, ...$unchangedFiles, ...$fetched->sharedFilesFrom($others)])->unique(fn (PackageFile $file): string => $file->sha256);

        return clone ($this, ['request' => $request, 'packages' => [...$fetched->packages, ...$unchangedPins], 'files' => array_values($files->all()), 'extensions' => $fetched->extensions]);
    }

    /**
     * @return list<string>
     */
    public function packageNames(): array
    {
        return array_values(array_unique(array_map(fn (string $pinned): string => Str::before($pinned, '='), $this->packages)));
    }

    /**
     * @return list<string>
     */
    public function extensionNames(): array
    {
        return array_map(fn (PackageExtension $extension): string => $extension->name, $this->extensions);
    }
}
