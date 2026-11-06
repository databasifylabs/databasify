<?php

declare(strict_types=1);

namespace App\DTOs\Migrations;

final readonly class MigrationPosition
{
    public function __construct(
        public ?string $file = null,
        public ?string $position = null,
        public ?string $binlog = null,
    ) {}

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            file: is_string($data['file'] ?? null) ? $data['file'] : null,
            position: is_string($data['position'] ?? null) ? $data['position'] : null,
            binlog: is_string($data['binlog'] ?? null) ? $data['binlog'] : null,
        );
    }

    /**
     * @return array{file?: string, position?: string, binlog?: string}
     */
    public function toArray(): array
    {
        return array_filter([
            'file' => $this->file,
            'position' => $this->position,
            'binlog' => $this->binlog,
        ], fn (?string $value): bool => $value !== null);
    }

    public function withCoordinates(string $file, string $position): self
    {
        return clone ($this, ['file' => $file, 'position' => $position]);
    }

    public function withBinlog(string $binlog): self
    {
        return clone ($this, ['binlog' => $binlog]);
    }
}
