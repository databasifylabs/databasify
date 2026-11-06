<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Plugins\PluginLoader;
use Illuminate\Support\ServiceProvider;

class PluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->make(PluginLoader::class)->reset();
    }

    public function boot(PluginLoader $loader): void
    {
        $loader->boot();
    }
}
