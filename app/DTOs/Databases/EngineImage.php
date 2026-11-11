<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

use App\Engines\Payload;

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
     * @param  array<array-key, mixed>  $values
     */
    public static function fromArray(array $values): self
    {
        $payload = new Payload($values);

        return new self($payload->string('repository'), $payload->string('digest'), $payload->string('release'), $payload->nullableString('sdk'));
    }

    /**
     * @return array{repository: string, digest: string, release: string, sdk: string|null}
     */
    public function toArray(): array
    {
        return ['repository' => $this->repository, 'digest' => $this->digest, 'release' => $this->release, 'sdk' => $this->sdk];
    }
}
