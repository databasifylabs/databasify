<?php

declare(strict_types=1);

namespace App\Engines\Data;

use App\Engines\Payload;

final readonly class Progress
{
    public function __construct(
        public int $done,
        public ?int $total,
        public string $unit,
    ) {}

    public static function fromPayload(Payload $payload): self
    {
        return new self($payload->int('done'), $payload->nullableInt('total'), $payload->string('unit'));
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    public static function fromArray(array $values): self
    {
        return self::fromPayload(new Payload($values));
    }

    /**
     * @return array{done: int, total: ?int, unit: string}
     */
    public function toArray(): array
    {
        return ['done' => $this->done, 'total' => $this->total, 'unit' => $this->unit];
    }

    public function describe(): string
    {
        return $this->total === null ? "{$this->done} {$this->unit}" : "{$this->done} of {$this->total} {$this->unit}";
    }
}
