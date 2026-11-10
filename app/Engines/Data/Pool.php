<?php

declare(strict_types=1);

namespace App\Engines\Data;

use App\Engines\Payload;

final readonly class Pool
{
    public function __construct(
        public string $name,
        public string $database,
        public int $size,
        public string $mode,
    ) {}

    public static function fromPayload(Payload $payload): self
    {
        return new self($payload->string('name'), $payload->string('database'), $payload->int('size'), $payload->string('mode'));
    }

    /**
     * @return array{name: string, database: string, size: int, mode: string}
     */
    public function toPayload(): array
    {
        return ['name' => $this->name, 'database' => $this->database, 'size' => $this->size, 'mode' => $this->mode];
    }
}
