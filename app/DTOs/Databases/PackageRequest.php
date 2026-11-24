<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

use App\Enums\Databases\PackageSource;

final readonly class PackageRequest
{
    /**
     * @param  list<string>  $uploads
     */
    public function __construct(
        public PackageSource $source,
        public ?string $package,
        public ?string $repository,
        public ?string $repositoryKey,
        public array $uploads,
    ) {}

    public function label(): string
    {
        return $this->package ?? implode(', ', array_map(basename(...), $this->uploads));
    }
}
