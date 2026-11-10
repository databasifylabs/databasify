<?php

declare(strict_types=1);

namespace App\Engines;

use App\Exceptions\Engines\EngineError;
use JsonException;

final readonly class Payload
{
    /**
     * @param  array<array-key, mixed>  $values
     */
    public function __construct(private array $values) {}

    public static function decode(string $json): self
    {
        try {
            $values = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new EngineError('internal', 'The engine replied with something that is not JSON.');
        }

        if (! is_array($values)) {
            throw new EngineError('internal', 'The engine replied with something that is not a JSON object.');
        }

        return new self($values);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function string(string $key): string
    {
        $value = $this->valueOf($key);

        return is_string($value) ? $value : throw $this->unexpected($key);
    }

    public function nullableString(string $key): ?string
    {
        $value = $this->valueOf($key);

        return $value === null || is_string($value) ? $value : throw $this->unexpected($key);
    }

    public function bool(string $key): bool
    {
        $value = $this->valueOf($key);

        return is_bool($value) ? $value : throw $this->unexpected($key);
    }

    public function int(string $key): int
    {
        $value = $this->valueOf($key);

        return is_int($value) ? $value : throw $this->unexpected($key);
    }

    public function nullableInt(string $key): ?int
    {
        $value = $this->valueOf($key);

        return $value === null || is_int($value) ? $value : throw $this->unexpected($key);
    }

    public function nullableFloat(string $key): ?float
    {
        $value = $this->valueOf($key);

        return match (true) {
            $value === null => null,
            is_int($value), is_float($value) => (float) $value,
            default => throw $this->unexpected($key),
        };
    }

    /**
     * @return list<string>
     */
    public function strings(string $key): array
    {
        $values = $this->listOf($key);

        return array_filter($values, is_string(...)) === $values ? $values : throw $this->unexpected($key);
    }

    /**
     * @return list<int>
     */
    public function ints(string $key): array
    {
        $values = $this->listOf($key);

        return array_filter($values, is_int(...)) === $values ? $values : throw $this->unexpected($key);
    }

    /**
     * @return list<self>
     */
    public function objects(string $key): array
    {
        return array_map(fn (mixed $value): self => is_array($value) ? new self($value) : throw $this->unexpected($key), $this->listOf($key));
    }

    /**
     * @return array<string, string>
     */
    public function stringMap(string $key): array
    {
        $value = $this->valueOf($key);
        $map = is_array($value) ? $value : throw $this->unexpected($key);

        return array_filter($map, fn (mixed $item, int|string $name): bool => is_string($item) && is_string($name), ARRAY_FILTER_USE_BOTH) === $map ? $map : throw $this->unexpected($key);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    public function nullableObject(string $key): ?self
    {
        $value = $this->valueOf($key);

        return $value === null ? null : $this->object($key);
    }

    public function object(string $key): self
    {
        $value = $this->valueOf($key);

        return is_array($value) ? new self($value) : throw $this->unexpected($key);
    }

    /**
     * @return list<mixed>
     */
    private function listOf(string $key): array
    {
        $value = $this->valueOf($key);

        return is_array($value) && array_is_list($value) ? $value : throw $this->unexpected($key);
    }

    private function valueOf(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    private function unexpected(string $key): EngineError
    {
        return new EngineError('internal', "The engine replied without a valid {$key}.");
    }
}
