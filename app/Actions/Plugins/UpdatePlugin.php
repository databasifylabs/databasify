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

            if ($package->key !== $plugin->key) {
                throw ValidationException::withMessages(['source' => "This zip is the {$package->key} plugin, not {$plugin->key}."]);
            }

            $incompatibility = Plugin::incompatibilityWith($package->requires);

            if ($incompatibility !== null) {
                throw ValidationException::withMessages(['source' => $incompatibility]);
            }

            $databases = $plugin->engine === null || $plugin->engine === $package->engine ? 0 : Database::query()->withoutProject()->where('engine', $plugin->engine)->count();

            if ($databases > 0) {
                throw ValidationException::withMessages(['source' => "This update removes the {$plugin->engine} engine, still used by {$databases} ".Str::plural('database', $databases).'.']);
            }

            $this->confirmCommunityEngines->handle($package->engine === $plugin->engine ? null : $package->engine, $package->author, $source);

            $new = "{$plugin->directory()}.new";
            $old = "{$plugin->directory()}.old";
            File::deleteDirectory($new);
            File::deleteDirectory($old);
            File::copyDirectory($files, $new);
            File::moveDirectory($plugin->directory(), $old);
            File::moveDirectory($new, $plugin->directory());
            File::deleteDirectory($old);
        } finally {
            File::deleteDirectory($download);
        }

        $plugin->update($package->toUpdateRow($source->label));
        Artisan::call('migrate', ['--force' => true]);
        Artisan::call('queue:restart');

        return $plugin;
    }
}
