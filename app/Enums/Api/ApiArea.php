<?php

declare(strict_types=1);

namespace App\Enums\Api;

use Laravel\Passport\Http\Middleware\CheckToken;
use Laravel\Passport\Http\Middleware\CheckTokenForAnyScope;

enum ApiArea: string
{
    case Projects = 'projects';
    case ServerProviders = 'server-providers';
    case DnsProviders = 'dns-providers';
    case StorageProviders = 'storage-providers';
    case Servers = 'servers';
    case Databases = 'databases';
    case System = 'system';

    /**
     * @return array<string, string>
     */
    public static function scopes(): array
    {
        return collect(self::cases())
            ->flatMap(fn (self $area): array => $area->scopeLabels())
            ->all();
    }

    /**
     * @return list<array{value: string, label: string, read_only: bool}>
     */
    public static function options(): array
    {
        return array_map(fn (self $area): array => ['value' => $area->value, 'label' => $area->label(), 'read_only' => $area->isReadOnly()], self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Projects => 'Projects',
            self::ServerProviders => 'Server providers',
            self::DnsProviders => 'DNS providers',
            self::StorageProviders => 'Storage providers',
            self::Servers => 'Servers',
            self::Databases => 'Databases',
            self::System => 'System',
        };
    }

    public function isReadOnly(): bool
    {
        return $this === self::System;
    }

    public function readScope(): string
    {
        return "{$this->value}:read";
    }

    public function writeScope(): string
    {
        return "{$this->value}:write";
    }

    public function readMiddleware(): string
    {
        return CheckTokenForAnyScope::using($this->readScope(), $this->writeScope());
    }

    public function writeMiddleware(): string
    {
        return CheckToken::using($this->writeScope());
    }

    /**
     * @param  list<string>  $scopes
     */
    public function levelIn(array $scopes): AccessLevel
    {
        return match (true) {
            in_array($this->writeScope(), $scopes, true) => AccessLevel::Write,
            in_array($this->readScope(), $scopes, true) => AccessLevel::Read,
            default => AccessLevel::None,
        };
    }

    /**
     * @return array<string, string>
     */
    private function scopeLabels(): array
    {
        $read = [$this->readScope() => "View {$this->label()}"];

        return $this->isReadOnly() ? $read : [...$read, $this->writeScope() => "Change {$this->label()}"];
    }
}
