<?php

declare(strict_types=1);

namespace App\DTOs\Ssh;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

final readonly class LogGroup
{
    private const string CONTEXT_KEY = 'server_log_group';

    public function __construct(
        public string $id,
        public string $name,
    ) {}

    public static function named(string $name): self
    {
        return new self((string) Str::uuid7(), $name);
    }

    public static function fromContext(): ?self
    {
        $group = Context::get(self::CONTEXT_KEY);

        if (! is_array($group) || ! is_string($group['id'] ?? null) || ! is_string($group['name'] ?? null)) {
            return null;
        }

        return new self($group['id'], $group['name']);
    }

    public static function forget(): void
    {
        Context::forget(self::CONTEXT_KEY);
    }

    public function toContext(): void
    {
        Context::add(self::CONTEXT_KEY, ['id' => $this->id, 'name' => $this->name]);
    }
}
