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
        private ConfirmCommunityEngines $confirmCommunityEngines,
    ) {}

    public function handle(PluginSource $source): Plugin
    {
        $download = $this->downloader->download($source->label, $source->archivePath);

        try {
            $staging = $this->downloader->packageIn($download);
            $package = PluginPackage::read($staging);
            $incompatibility = Plugin::incompatibilityWith($package->requires);

            if ($incompatibility !== null) {
                throw ValidationException::withMessages(['source' => $incompatibility]);
            }

            if (preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $package->id) !== 1 || $package->id === 'core' || Plugin::query()->whereKey($package->id)->exists()) {
                throw ValidationException::withMessages(['source' => "A plugin called \"{$package->id}\" is already installed or the name is not allowed."]);
            }

            $this->confirmCommunityEngines->handle($package->engines, $package->author, $source);
            $plugin = new Plugin($package->toInstallRow($source->label));
            File::ensureDirectoryExists(Plugin::root());
            File::copyDirectory($staging, $plugin->directory());
        } finally {
            File::deleteDirectory($download);
        }

        $error = $this->loader->load($plugin);

        if ($error !== null) {
            File::deleteDirectory($plugin->directory());

            throw ValidationException::withMessages(['source' => "The plugin failed to load: {$error}"]);
        }

        $plugin->save();
        Artisan::call('migrate', ['--force' => true]);

        try {
            $this->loader->call($plugin, 'install');
        } catch (Throwable $exception) {
            report($exception);
            $plugin->delete();
            File::deleteDirectory($plugin->directory());

            throw ValidationException::withMessages(['source' => "The plugin failed to install: {$exception->getMessage()}"]);
        }

        Artisan::call('queue:restart');

        return $plugin;
    }
}
