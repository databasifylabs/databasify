<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Sentinel;

use InvalidArgumentException;

final readonly class SentinelFamilyEngine
{
    private function __construct(
        public string $id,
        public string $cli,
        public string $server,
        public string $checkRdb,
        public string $versionField,
    ) {}

    public static function of(string $engine): self
    {
        return match ($engine) {
            'valkey' => new self('valkey', 'valkey-cli', 'valkey-server', 'valkey-check-rdb', 'valkey_version'),
            'redis' => new self('redis', 'redis-cli', 'redis-server', 'redis-check-rdb', 'redis_version'),
            default => throw new InvalidArgumentException("{$engine} is not a Sentinel family engine."),
        };
    }

    public function home(): string
    {
        return "/srv/{$this->id}";
    }

    public function serverUnit(): string
    {
        return "{$this->id}-server";
    }

    public function sentinelUnit(): string
    {
        return "{$this->id}-sentinel";
    }

    public function migrationUnit(): string
    {
        return "{$this->id}-migration";
    }

    public function confPath(): string
    {
        return "conf/{$this->id}.conf";
    }
}
