<?php

declare(strict_types=1);

namespace App\Services\Http;

final readonly class Payload
{
    /**
     * @param  array<mixed>  $data
     */
    public function __construct(private array $data) {}

    public static function of(mixed $value): self
    {
        return new self(is_array($value) ? $value : []);
    }

    public function string(string $key, string $default = ''): string
    {
        return $this->stringOrNull($key) ?? $default;
    }

    public function stringOrNull(string $key): ?string
    {
        $value = data_get($this->data, $key);

        return is_string($value) || is_int($value) ? (string) $value : null;
    }

    public function int(string $key): int
    {
        $value = data_get($this->data, $key);

        return is_numeric($value) ? (int) $value : 0;
    }

    public function floatOrNull(string $key): ?float
    {
        $value = data_get($this->data, $key);

        return is_numeric($value) ? (float) $value : null;
    }

    public function bool(string $key): bool
    {
        return data_get($this->data, $key) === true;
    }

    /**
     * @return list<string>
     */
    public function strings(string $key): array
    {
        $values = data_get($this->data, $key);

        return is_array($values) ? array_values(array_map(strval(...), array_filter($values, fn (mixed $value): bool => is_string($value) || is_int($value)))) : [];
    }

    public function item(string $key): self
    {
        return self::of(data_get($this->data, $key));
    }

    /**
     * @return list<self>
     */
    public function items(string $key): array
    {
        $values = data_get($this->data, $key);

        return is_array($values) ? array_values(array_map(self::of(...), array_filter($values, is_array(...)))) : [];
    }
}
