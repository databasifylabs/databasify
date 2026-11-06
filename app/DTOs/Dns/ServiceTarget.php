<?php

declare(strict_types=1);

namespace App\DTOs\Dns;

final readonly class ServiceTarget
{
    public function __construct(
        public string $host,
        public int $port,
    ) {}

    public static function fromContent(string $content): self
    {
        $parts = explode(' ', trim($content));

        return new self(rtrim($parts[3] ?? '', '.'), (int) ($parts[2] ?? 0));
    }

    public function key(): string
    {
        return "{$this->host}:{$this->port}";
    }

    public function toContent(): string
    {
        return "0 0 {$this->port} {$this->host}";
    }
}
