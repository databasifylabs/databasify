<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

use App\DTOs\Recovery\RecoverySource;
use App\Enums\Databases\Persistence;

final readonly class DatabaseSettings
{
    /**
     * @param  array<string, string>  $secrets
     * @param  array<string, string>  $certificates
     * @param  array<string, string>  $config
     */
    public function __construct(
        public array $secrets,
        public array $certificates = [],
        public array $config = [],
        public Persistence $persistence = Persistence::Snapshots,
        public ?RecoverySource $restore = null,
    ) {}

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            secrets: self::stringMap($data['secrets'] ?? []),
            certificates: self::stringMap($data['certificates'] ?? []),
            config: self::stringMap($data['config'] ?? []),
            persistence: Persistence::tryFrom(self::stringOrNull($data['persistence'] ?? null) ?? '') ?? Persistence::Snapshots,
            restore: is_array($data['restore'] ?? null) ? RecoverySource::fromArray($data['restore']) : null,
        );
    }

    /**
     * @return array{secrets: array<string, string>, certificates: array<string, string>, config: array<string, string>, persistence: string, restore: array{database: string, storage_provider_id: string, path: string, target: string}|null}
     */
    public function toArray(): array
    {
        return [
            'secrets' => $this->secrets,
            'certificates' => $this->certificates,
            'config' => $this->config,
            'persistence' => $this->persistence->value,
            'restore' => $this->restore?->toArray(),
        ];
    }

    public function secret(string $name): string
    {
        return $this->secrets[$name] ?? '';
    }

    public function defaultSchema(): string
    {
        return $this->secret('DB_DATABASE');
    }

    public function defaultUsername(): string
    {
        return $this->secret('DB_USERNAME');
    }

    public function defaultPassword(): string
    {
        return $this->secret('DB_PASSWORD');
    }

    public function certificate(string $name): string
    {
        return $this->certificates[$name] ?? '';
    }

    public function nodeCertificate(string $nodeId, string $name): ?string
    {
        return $this->certificates["node-{$nodeId}/{$name}"] ?? null;
    }

    /**
     * @param  array<string, string>  $files
     */
    public function withNodeCertificates(string $nodeId, array $files): self
    {
        return $this->withCertificates(array_merge(
            $this->withoutNodeCertificates($nodeId)->certificates,
            collect($files)->mapWithKeys(fn (string $content, string $name): array => ["node-{$nodeId}/{$name}" => $content])->all(),
        ));
    }

    public function withoutNodeCertificates(string $nodeId): self
    {
        return $this->withCertificates(array_filter(
            $this->certificates,
            fn (string $name): bool => ! str_starts_with($name, "node-{$nodeId}/"),
            ARRAY_FILTER_USE_KEY,
        ));
    }

    /**
     * @param  array<string, string>  $certificates
     */
    public function withCertificates(array $certificates): self
    {
        return clone ($this, ['certificates' => $certificates]);
    }

    /**
     * @param  array<string, string>  $certificates
     */
    public function mergingCertificates(array $certificates): self
    {
        return $this->withCertificates(array_merge($this->certificates, $certificates));
    }

    /**
     * @param  array<string, string>  $config
     */
    public function withConfig(array $config): self
    {
        return clone ($this, ['config' => $config]);
    }

    public function withPersistence(Persistence $persistence): self
    {
        return clone ($this, ['persistence' => $persistence]);
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(mixed $value): array
    {
        $map = [];

        foreach (is_array($value) ? $value : [] as $key => $item) {
            if (is_string($key) && is_string($item)) {
                $map[$key] = $item;
            }
        }

        return $map;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
