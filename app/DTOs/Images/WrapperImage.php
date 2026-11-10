<?php

declare(strict_types=1);

namespace App\DTOs\Images;

final readonly class WrapperImage
{
    public function __construct(
        public string $repository,
        public string $version,
        public string $digest,
    ) {}

    public function reference(): string
    {
        return "{$this->repository}:{$this->version}@{$this->digest}";
    }

    /**
     * @return array{wrapper_version: string, wrapper_digest: string}
     */
    public function toRow(): array
    {
        return ['wrapper_version' => $this->version, 'wrapper_digest' => $this->digest];
    }
}
