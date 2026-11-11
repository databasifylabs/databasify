<?php

declare(strict_types=1);

namespace App\Actions\Plugins;

use App\DTOs\Plugins\PluginPackage;
use App\DTOs\Plugins\PluginSource;
use App\Models\Database;
use App\Models\Plugin;
use App\Services\Plugins\PluginDownloader;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class UpdatePlugin
{
    public function __construct(
        private PluginDownloader $downloader,
        private ConfirmCommunityEngines $confirmCommunityEngines,
    ) {}

    public function handle(Plugin $plugin, PluginSource $source): Plugin
    {
        if ($source->archivePath === null && $plugin->wasUploaded()) {
            throw ValidationException::withMessages(['archive' => "{$plugin->name} was uploaded, so upload its newer zip."]);
        }

        $download = $this->downloader->download($source->label, $source->archivePath);

        try {
            $files = $this->downloader->packageIn($download);
            $package = PluginPackage::read($files);
            $this->ensureReplaces($plugin, $package);
            $this->ensureKeepsEnginesInUse($plugin, $package);
            $this->confirmCommunityEngines->handle(array_values(array_diff($package->engines, $plugin->engines)), $package->author, $source);
            $this->swap($plugin, $files);
        } finally {
            File::deleteDirectory($download);
        }

        $plugin->update($package->toUpdateRow($source->label));
        Artisan::call('migrate', ['--force' => true]);
        Artisan::call('queue:restart');

        return $plugin;
    }

    private function ensureReplaces(Plugin $plugin, PluginPackage $package): void
    {
        if ($package->id !== $plugin->id) {
            throw ValidationException::withMessages(['source' => "This zip is the {$package->id} plugin, not {$plugin->id}."]);
        }

        $incompatibility = Plugin::incompatibilityWith($package->requires);

        if ($incompatibility !== null) {
            throw ValidationException::withMessages(['source' => $incompatibility]);
        }
    }

    private function ensureKeepsEnginesInUse(Plugin $plugin, PluginPackage $package): void
    {
        foreach (array_diff($plugin->engines, $package->engines) as $engine) {
            $databases = Database::query()->withoutProject()->where('engine', $engine)->count();

            if ($databases > 0) {
                throw ValidationException::withMessages(['source' => "This update removes the {$engine} engine, still used by {$databases} ".Str::plural('database', $databases).'.']);
            }
        }
    }

    private function swap(Plugin $plugin, string $files): void
    {
        $new = Plugin::root()."/.{$plugin->id}-new";
        $old = Plugin::root()."/.{$plugin->id}-old";
        File::deleteDirectory($new);
        File::deleteDirectory($old);
        File::copyDirectory($files, $new);
        File::moveDirectory($plugin->directory(), $old);
        File::moveDirectory($new, $plugin->directory());
        File::deleteDirectory($old);
    }
}
