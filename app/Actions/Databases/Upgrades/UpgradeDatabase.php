<?php

declare(strict_types=1);

namespace App\Actions\Databases\Upgrades;

use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\UpgradeDatabaseJob;
use App\Models\Database;
use Illuminate\Validation\ValidationException;

final readonly class UpgradeDatabase
{
    public function handle(Database $database): void
    {
        $database->ensureNotBusy();
        $image = $database->availableImage()
            ?? throw ValidationException::withMessages(['version' => "{$database->name} already runs the newest {$database->versionLabel()} release."]);

        $label = $database->engine()->label;

        if (! $image->hasSupportedSdk()) {
            throw ValidationException::withMessages(['version' => $image->sdkRefusalFor($label)]);
        }

        $database->markBusyWithReadOnly(DatabaseStatus::Upgrading, function (Database $locked) use ($image): void {
            $settings = $locked->settings;

            if (! $settings->packagesPreparedFor($image->digest)) {
                throw ValidationException::withMessages(['version' => "Custom packages are being prepared for {$image->release}. Try again in a few minutes."]);
            }

            $locked->recordInstalledImage($image);
            $locked->update(['settings' => $settings->withPackages($settings->packages === [] ? [] : $settings->nextPackages->packages ?? [])->withNextPackages(null)]);
        });
        dispatch(new UpgradeDatabaseJob($database->refresh()));
    }
}
