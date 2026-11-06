<?php

declare(strict_types=1);

namespace App\Actions\Plugins;

use App\Models\Plugin;
use App\Services\Plugins\PluginLoader;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class SetPluginEnabled
{
    public function __construct(private PluginLoader $loader) {}

    public function handle(Plugin $plugin, bool $enabled): void
    {
        $this->callHookOf($plugin, $enabled ? 'enable' : 'disable');
        $plugin->setEnabled($enabled);
        Artisan::call('queue:restart');
    }

    /**
     * @param  'enable'|'disable'  $hook
     */
    private function callHookOf(Plugin $plugin, string $hook): void
    {
        if (! is_dir($plugin->directory())) {
            return;
        }

        try {
            $this->loader->call($plugin, $hook);
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages(['plugin' => "{$plugin->name} failed to {$hook}: {$exception->getMessage()}"]);
        }
    }
}
