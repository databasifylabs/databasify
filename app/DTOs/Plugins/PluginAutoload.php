<?php

declare(strict_types=1);

namespace App\DTOs\Plugins;

final readonly class PluginAutoload
{
    /**
     * @param  array<string, string>  $namespaces
     */
    public function __construct(public array $namespaces) {}

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $namespaces = [];

        foreach ($data as $prefix => $path) {
            if (is_string($prefix) && is_string($path)) {
                $namespaces[$prefix] = $path;
            }
        }

        return new self($namespaces);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->namespaces;
    }

    public function isEmpty(): bool
    {
        return $this->namespaces === [];
    }
}
