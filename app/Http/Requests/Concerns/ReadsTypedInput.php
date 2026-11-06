<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

trait ReadsTypedInput
{
    public function stringOrNull(string $key): ?string
    {
        return $this->filled($key) ? $this->string($key)->value() : null;
    }

    public function integerOr(string $key, int $default): int
    {
        return $this->filled($key) ? $this->integer($key) : $default;
    }

    /**
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        return array_values(array_filter($this->array($key), is_string(...)));
    }
}
