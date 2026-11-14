<?php

declare(strict_types=1);

namespace App\Actions\Databases\Upgrades;

use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\UpgradeDatabaseJob;
use App\Models\Database;
use App\Support\Engines\SdkRange;
use Illuminate\Validation\ValidationException;

final readonly class UpgradeDatabase
{
    public function handle(Database $database): void
    {
        $database->ensureNotBusy();
        $image = $database->availableImage()
            ?? throw ValidationException::withMessages(['version' => "{$database->name} already runs the newest {$database->versionLabel()} release."]);

        $label = $database->engine()->label;

        if (! SdkRange::supports($image)) {
            throw ValidationException::withMessages(['version' => SdkRange::refusalFor($label, $image)]);
        }

        $database->markBusyWith(DatabaseStatus::Upgrading, fn (Database $locked) => $locked->recordInstalledImage($image));
        dispatch(new UpgradeDatabaseJob($database->refresh()));
    }
}
