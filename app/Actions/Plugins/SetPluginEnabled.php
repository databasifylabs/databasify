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
        $hook = $enabled ? 'enable' : 'disable';

        if (is_dir($plugin->directory())) {
            try {
                $this->loader->call($plugin, $hook);
            } catch (Throwable $exception) {
                report($exception);

                throw ValidationException::withMessages(['plugin' => "{$plugin->name} failed to {$hook}: {$exception->getMessage()}"]);
            }
        }

        $plugin->setEnabled($enabled);
        Artisan::call('queue:restart');
    }
}
