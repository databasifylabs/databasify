<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

use App\Enums\Databases\PackageFormat;

final readonly class PackagePlatform
{
    /**
     * @param  list<string>  $architectures
     */
    public function __construct(
        public string $os,
        public string $engine,
        public PackageRepository $repository,
        public PackageFormat $format,
        public array $architectures,
    ) {}

    /**
     * @return array{os: string, engine: string, repository: string, repository_host: string, example_package: string, package_format: string, repository_label: string, repository_example: string, architectures: list<string>}
     */
    public function toArray(): array
    {
        return [
            'os' => $this->os,
            'engine' => $this->engine,
            'repository' => $this->repository->name,
            'repository_host' => $this->repository->host,
            'example_package' => $this->repository->examplePackage,
            'package_format' => $this->format->value,
            'repository_label' => $this->format->repositoryLabel(),
            'repository_example' => $this->format->repositoryExample(),
            'architectures' => $this->architectures,
        ];
    }
}
