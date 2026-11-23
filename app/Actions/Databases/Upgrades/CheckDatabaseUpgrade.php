<?php

declare(strict_types=1);

namespace App\Actions\Databases\Upgrades;

use App\DTOs\Databases\EngineImage;
use App\Events\DatabaseAlertRaised;
use App\Exceptions\Images\ImageUnavailable;
use App\Jobs\Databases\PrepareExtensionPackagesJob;
use App\Models\Database;
use App\Services\Images\ImageResolver;
use App\Support\Databases\UpgradeCache;
use Illuminate\Validation\ValidationException;

final readonly class CheckDatabaseUpgrade
{
    public function __construct(
        private ImageResolver $resolver,
        private UpgradeCache $cache,
    ) {}

    public function handle(Database $database): ?EngineImage
    {
        try {
            $images = $this->resolver->imagesOf($database, $database->version_line);
        } catch (ImageUnavailable $unavailable) {
            throw ValidationException::withMessages(['version' => $unavailable->getMessage()]);
        }

        $newest = $images->newest;

        if ($newest->hasSupportedSdk()) {
            $this->cache->forgetSdkRefusal($database);
        } elseif ($this->cache->rememberSdkRefusal($database, $newest->digest)) {
            $label = $database->engine()->label;
            event(new DatabaseAlertRaised($database, "{$database->name} stays on {$label} {$database->image->release}", $newest->sdkRefusalFor($label)));
        }

        if ($images->supported === null) {
            return null;
        }

        $image = $images->supported->replaces($database->image()) ? $images->supported : null;

        if ($image !== null && ! $database->settings->packagesPreparedFor($image->digest)) {
            $database->recordAvailableImage(null);

            if (! $this->cache->preparationFailed($database, $image->digest)) {
                dispatch(new PrepareExtensionPackagesJob($database, $image));
            }

            return null;
        }

        $database->recordAvailableImage($image);

        return $image;
    }
}
