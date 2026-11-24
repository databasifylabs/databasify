<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

use App\Engines\Payload;

final readonly class FetchedPackages
{
    /**
     * @param  list<string>  $packages
     * @param  list<PackageFile>  $files
     * @param  list<PackageExtension>  $extensions
     * @param  list<string>  $requires
     */
    public function __construct(
        public array $packages,
        public array $files,
        public array $extensions,
        public ?string $refusal,
        public array $requires = [],
        public bool $alreadyInstalled = false,
    ) {}

    public static function fromPayload(Payload $payload): self
    {
        return new self(
            $payload->strings('packages'),
            array_map(PackageFile::fromHost(...), $payload->objects('files')),
            array_map(PackageExtension::fromPayload(...), $payload->objects('extensions')),
            $payload->nullableString('refusal'),
            $payload->strings('requires'),
            $payload->bool('alreadyInstalled'),
        );
    }

    /**
     * @param  list<ExtensionPackage>  $packages
     * @return list<PackageFile>
     */
    public function sharedFilesFrom(array $packages): array
    {
        return array_values(collect($packages)
            ->flatMap(fn (ExtensionPackage $package): array => $package->files)
            ->filter(fn (PackageFile $file): bool => in_array($file->package, $this->requires, true))
            ->unique(fn (PackageFile $file): string => $file->sha256)
            ->all());
    }
}
