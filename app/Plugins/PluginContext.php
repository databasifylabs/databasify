<?php

declare(strict_types=1);

namespace App\Plugins;

final readonly class PluginContext
{
    public function __construct(private string $assets) {}

    public function database(string $id): RegisterEngine
    {
        return RegisterEngine::make($id)->icon($this->asset("icons/database-providers/{$id}.svg"));
    }

    public function dns(string $id): RegisterDnsProvider
    {
        return RegisterDnsProvider::make($id)->icon($this->asset("icons/dns-providers/{$id}.svg"));
    }

    public function server(string $id): RegisterServerProvider
    {
        return RegisterServerProvider::make($id)->icon($this->asset("icons/server-providers/{$id}.svg"));
    }

    public function asset(string $path): string
    {
        return "{$this->assets}/{$path}";
    }
}
