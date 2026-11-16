<?php

declare(strict_types=1);

namespace App\Actions\Plugins;

use App\Models\Plugin;
use App\Services\Plugins\PluginLoader;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class UninstallPlugin
{
    public function __construct(private PluginLoader $loader, private Migrator $migrator) {}

    public function handle(Plugin $plugin): void
    {
        $root = realpath($plugin->directory());

        if ($root !== false) {
            try {
                if ($this->loader->hasCode($plugin)) {
                    $this->loader->call($plugin, 'uninstall');
                }

                $paths = collect($this->migrator->paths())
                    ->push("{$plugin->directory()}/database/migrations")
                    ->map(fn (string $path): string|false => realpath($path))
                    ->filter(fn (string|false $path): bool => is_string($path) && ($path === $root || str_starts_with($path, $root.DIRECTORY_SEPARATOR)))
                    ->unique()
                    ->values()
                    ->all();

                if ($paths !== []) {
                    Artisan::call('migrate:reset', ['--path' => $paths, '--realpath' => true, '--force' => true]);
                }
            } catch (Throwable $exception) {
                report($exception);

                throw ValidationException::withMessages(['plugin' => "{$plugin->name} failed to uninstall: {$exception->getMessage()}"]);
            }
        }

        $plugin->delete();
        File::deleteDirectory($plugin->directory());
        Artisan::call('queue:restart');
    }
}
