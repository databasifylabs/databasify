<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

final readonly class EngineImage
{
    public function __construct(
        public string $repository,
        public string $digest,
        public string $release,
        public ?string $sdk,
    ) {}

    public function reference(): string
    {
        return "{$this->repository}@{$this->digest}";
    }

    public function pinnedReference(): string
    {
        return "{$this->repository}:{$this->release}@{$this->digest}";
    }

    public function replaces(self $current): bool
    {
        $comparison = version_compare($this->release, $current->release);

        return $comparison > 0 || ($comparison === 0 && $this->reference() !== $current->reference());
    }

    public function refreshes(self $current): bool
    {
        return $this->release === $current->release;
    }

    /**
     * @return array{image_repository: string, image_digest: string, image_release: string, image_sdk: string|null}
     */
    public function toRow(): array
    {
        return ['image_repository' => $this->repository, 'image_digest' => $this->digest, 'image_release' => $this->release, 'image_sdk' => $this->sdk];
    }

    /**
     * @return array{image_repository: string, image_digest: string, image_release: string, image_sdk: string|null, available_image_repository: null, available_image_digest: null, available_image_release: null, available_image_sdk: null}
     */
    public function toInstalledRow(): array
    {
        return array_merge($this->toRow(), self::noneAvailableRow());
    }

    /**
     * @return array{available_image_repository: string, available_image_digest: string, available_image_release: string, available_image_sdk: string|null}
     */
    public function toAvailableRow(): array
    {
        return ['available_image_repository' => $this->repository, 'available_image_digest' => $this->digest, 'available_image_release' => $this->release, 'available_image_sdk' => $this->sdk];
    }

    /**
     * @return array{available_image_repository: null, available_image_digest: null, available_image_release: null, available_image_sdk: null}
     */
    public static function noneAvailableRow(): array
    {
        return ['available_image_repository' => null, 'available_image_digest' => null, 'available_image_release' => null, 'available_image_sdk' => null];
    }
}
