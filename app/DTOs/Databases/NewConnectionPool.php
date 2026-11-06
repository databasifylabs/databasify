<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

use App\Enums\Databases\PoolMode;

final readonly class NewConnectionPool
{
    public function __construct(
        public string $name,
        public string $schema,
        public PoolMode $mode,
        public int $size,
    ) {}

    /**
     * @return array{name: string, schema: string, mode: PoolMode, size: int}
     */
    public function toRow(): array
    {
        return ['name' => $this->name, 'schema' => $this->schema, 'mode' => $this->mode, 'size' => $this->size];
    }
}
