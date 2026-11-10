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

    public function object(string $key): self
    {
        $value = $this->valueOf($key);

        return is_array($value) ? new self($value) : throw $this->unexpected($key);
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
