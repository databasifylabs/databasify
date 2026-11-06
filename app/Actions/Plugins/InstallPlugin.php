<?php

declare(strict_types=1);

namespace App\Actions\Plugins;

use App\DTOs\Plugins\PluginPackage;
use App\DTOs\Plugins\PluginSource;
use App\Models\Plugin;
use App\Services\Plugins\PluginDownloader;
use App\Services\Plugins\PluginLoader;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class InstallPlugin
{
    public function __construct(
        private PluginDownloader $downloader,
        private PluginLoader $loader,
    ) {}

    public function handle(PluginSource $source): Plugin
    {
        $download = $this->downloader->download($source->label, $source->archivePath);

        try {
            $plugin = $this->stage($source->label, $this->downloader->packageIn($download));
        } finally {
            File::deleteDirectory($download);
        }

        $this->ensureLoads($plugin);
        $plugin->save();
        Artisan::call('migrate', ['--force' => true]);
        $this->runInstallHookOf($plugin);
        Artisan::call('queue:restart');

        return $plugin;
    }

    private function ensureLoads(Plugin $plugin): void
    {
        $error = $this->loader->load($plugin);

        if ($error === null) {
            return;
        }

        File::deleteDirectory($plugin->directory());

        throw ValidationException::withMessages(['source' => "The plugin failed to load: {$error}"]);
    }

    private function runInstallHookOf(Plugin $plugin): void
    {
        try {
            $this->loader->call($plugin, 'install');
        } catch (Throwable $exception) {
            report($exception);
            $plugin->delete();
            File::deleteDirectory($plugin->directory());

            throw ValidationException::withMessages(['source' => "The plugin failed to install: {$exception->getMessage()}"]);
        }
    }

    private function stage(string $source, string $staging): Plugin
    {
        $package = PluginPackage::read($staging);
        $incompatibility = Plugin::incompatibilityWith($package->requires);

        if ($incompatibility !== null) {
            throw ValidationException::withMessages(['source' => $incompatibility]);
        }

        if ($this->isUnavailable($package->id)) {
            throw ValidationException::withMessages(['source' => "A plugin called \"{$package->id}\" is already installed or the name is not allowed."]);
        }

        $plugin = new Plugin($package->toInstallRow($source));
        File::ensureDirectoryExists(Plugin::root());
        File::copyDirectory($staging, $plugin->directory());

        return $plugin;
    }

    private function isUnavailable(string $id): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $id) !== 1 || $id === 'core' || Plugin::query()->whereKey($id)->exists();
    }
}
