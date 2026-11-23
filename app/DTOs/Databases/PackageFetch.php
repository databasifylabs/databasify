<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

use App\Enums\Databases\PackageSource;

final readonly class PackageFetch
{
    /**
     * @param  list<string>  $replacing
     * @param  list<EnabledExtension>  $enabled
     */
    public function __construct(
        public PackageSource $source,
        public string $line,
        public ?string $package,
        public ?string $repository,
        public ?string $repositoryKey,
        public string $run,
        public array $replacing = [],
        public array $enabled = [],
    ) {}

    public function signedSourceLine(): string
    {
        return preg_replace('/^deb /', "deb [signed-by={$this->keyPath()}] ", $this->repository ?? '') ?? '';
    }

    public function repositoryAddress(): string
    {
        return preg_replace('#^https?://#', '', explode(' ', $this->repository ?? '')[1] ?? '') ?? '';
    }

    public function keyPath(): string
    {
        return "/etc/apt/keyrings/databasify-{$this->run}.asc";
    }
}
