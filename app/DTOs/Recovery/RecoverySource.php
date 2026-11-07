<?php

declare(strict_types=1);

namespace App\DTOs\Recovery;

use App\Models\StorageProvider;
use Carbon\CarbonImmutable;

final readonly class RecoverySource
{
    public function __construct(
        public string $database,
        public string $storageProviderId,
        public string $path,
        public CarbonImmutable $target,
    ) {}

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $database = $data['database'] ?? null;
        $provider = $data['storage_provider_id'] ?? null;
        $path = $data['path'] ?? null;
        $target = $data['target'] ?? null;

        if (! is_string($database) || ! is_string($provider) || ! is_string($path) || ! is_string($target)) {
            return null;
        }

        return new self($database, $provider, $path, CarbonImmutable::parse($target)->utc());
    }

    /**
     * @return array{database: string, storage_provider_id: string, path: string, target: string}
     */
    public function toArray(): array
    {
        return [
            'database' => $this->database,
            'storage_provider_id' => $this->storageProviderId,
            'path' => $this->path,
            'target' => $this->target->toIso8601String(),
        ];
    }

    public function location(): ArchiveLocation
    {
        return new ArchiveLocation(StorageProvider::query()->withoutProject()->findOrFail($this->storageProviderId)->bucket(), $this->path);
    }

    public function targetForTools(): string
    {
        return $this->target->format('Y-m-d H:i:s');
    }
}
