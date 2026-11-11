<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Exceptions\Plugins\UndeclaredEngine;
use App\Models\Plugin;

final readonly class PluginContext
{
    public function __construct(private Plugin $plugin) {}

    public function database(string $id): RegisterEngine
    {
        if (! in_array($id, $this->plugin->engines, true)) {
            throw UndeclaredEngine::named($id);
        }

        return RegisterEngine::make($id)
            ->icon($this->asset("icons/database-providers/{$id}.svg"))
            ->community($this->plugin->id, $this->plugin->author);
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
        return "plugin-assets/{$this->plugin->id}/{$path}";
    }
}
