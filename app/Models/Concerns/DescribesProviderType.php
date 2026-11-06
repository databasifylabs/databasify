<?php

declare(strict_types=1);

namespace App\Models\Concerns;

trait DescribesProviderType
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function catalog(): array
    {
        return array_values(array_map(fn ($driver): array => $driver->toArray(), static::providers()));
    }

    public function isInstalled(): bool
    {
        return in_array($this->type, static::types(), true);
    }

    public function label(): string
    {
        return $this->isInstalled() ? $this->provider()->label() : "{$this->type} (not installed)";
    }

    public function icon(): ?string
    {
        return $this->isInstalled() ? $this->provider()->icon() : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function describedType(): array
    {
        return $this->isInstalled() ? $this->provider()->toArray() : ['value' => $this->type, 'label' => $this->label(), 'icon' => null, 'form' => []];
    }
}
