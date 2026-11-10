<?php

declare(strict_types=1);

namespace App\Actions\Databases\Upgrades;

use App\Actions\Databases\Flows\StartFlow;
use App\Engines\Flows\RollingSteps;
use App\Engines\Flows\UpgradeFlow;
use App\Models\Database;
use Illuminate\Validation\ValidationException;

final readonly class UpgradeDatabase
{
    public function __construct(
        private StartFlow $startFlow,
        private UpgradeFlow $flow,
    ) {}

    public function handle(Database $database): void
    {
        $database->ensureIdleOnAnyWrapper();
        $image = $database->availableImage()
            ?? throw ValidationException::withMessages(['version' => "{$database->name} already runs the newest {$database->versionLabel()} release."]);

        $label = $database->engine()->label;
        $title = $database->availableUpdateRefreshesImage() ? "Refresh the {$label} {$image->release} image" : "Install {$label} {$image->release}";
        $database->recordInstalledImage($image);

        $this->startFlow->handle($database->refresh(), $this->flow, RollingSteps::contextFor($database));
        $database->retitleOperation($title);
    }
}
