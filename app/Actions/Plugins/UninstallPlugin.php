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
        $this->uninstallFilesOf($plugin);
        $plugin->delete();
        File::deleteDirectory($plugin->directory());
        Artisan::call('queue:restart');
    }

    private function uninstallFilesOf(Plugin $plugin): void
    {
        if (! is_dir($plugin->directory())) {
            return;
        }

        try {
            $this->loader->call($plugin, 'uninstall');
            $this->resetMigrations($plugin);
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages(['plugin' => "{$plugin->name} failed to uninstall: {$exception->getMessage()}"]);
        }
    }

    private function resetMigrations(Plugin $plugin): void
    {
        $root = realpath($plugin->directory());

        if ($root === false) {
            return;
        }

        $paths = collect($this->migrator->paths())
            ->push("{$plugin->directory()}/database/migrations")
            ->map(fn (string $path): string|false => realpath($path))
            ->filter(fn (string|false $path): bool => is_string($path) && $this->isInside($path, $root))
            ->unique()
            ->values()
            ->all();

        if ($paths === []) {
            return;
        }

        Artisan::call('migrate:reset', ['--path' => $paths, '--realpath' => true, '--force' => true]);
    }

    private function isInside(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, $root.DIRECTORY_SEPARATOR);
    }
}
