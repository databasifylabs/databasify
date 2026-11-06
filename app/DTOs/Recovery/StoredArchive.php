<?php

declare(strict_types=1);

namespace App\DTOs\Recovery;

final readonly class StoredArchive
{
    public function __construct(
        public string $storageProviderId,
        public string $path,
    ) {}

    public function prefix(): string
    {
        return rtrim($this->path, '/').'/';
    }

    public function folder(): string
    {
        return dirname(rtrim($this->path, '/')).'/';
    }
}
