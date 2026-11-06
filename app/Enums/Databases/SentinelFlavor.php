<?php

declare(strict_types=1);

namespace App\Enums\Databases;

enum SentinelFlavor: string
{
    case Valkey = 'valkey';
    case Redis = 'redis';

    private const string BIN_DIRECTORY = '/usr/local/bin';

    public function env(string $name): string
    {
        return strtoupper($this->value)."_{$name}";
    }

    public function binary(string $tool): string
    {
        return self::BIN_DIRECTORY."/{$this->value}-{$tool}";
    }

    public function serverUnit(): string
    {
        return "databasify-{$this->value}";
    }

    public function sentinelUnit(): string
    {
        return "databasify-{$this->value}-sentinel";
    }
}
