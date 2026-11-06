<?php

declare(strict_types=1);

namespace App\Actions\Databases\Upgrades;

use App\DTOs\Databases\EngineImage;
use App\Jobs\Databases\UpgradeDatabaseJob;
use App\Models\Database;
use Illuminate\Validation\ValidationException;

final readonly class UpgradeDatabase
{
    public function handle(Database $database): void
    {
        $database->ensureIdle();
        $image = $database->availableImage()
            ?? throw ValidationException::withMessages(['version' => "{$database->name} already runs the newest {$database->versionLabel()} release."]);

        $database->startOperation($this->titleFor($database, $image));
        dispatch(new UpgradeDatabaseJob($database, installUpdate: true));
    }

    private function titleFor(Database $database, EngineImage $image): string
    {
        $label = $database->provider()->label();

        return $database->availableUpdateRefreshesImage() ? "Refresh the {$label} {$image->release} image" : "Install {$label} {$image->release}";
    }
}
