<?php

declare(strict_types=1);

namespace App\Actions\Databases\Upgrades;

use App\Actions\Databases\Flows\StartFlow;
use App\Engines\Flows\RollingSteps;
use App\Engines\Flows\UpdateWrapperFlow;
use App\Models\Database;
use App\Services\Images\WrapperResolver;

final readonly class UpdateDatabaseWrapper
{
    public function __construct(
        private WrapperResolver $wrappers,
        private StartFlow $startFlow,
        private UpdateWrapperFlow $flow,
    ) {}

    public function handle(Database $database): void
    {
        $api = $database->wrapperApi();

        if ($api === 0 || ! $database->isReady() || $database->isBusy() || $database->isMigrating()) {
            return;
        }

        $newest = $this->wrappers->newestFor($api);

        if ($newest === $database->wrapper_image) {
            return;
        }

        $database->update(['wrapper_image' => $newest]);
        $this->startFlow->handle($database, $this->flow, RollingSteps::contextFor($database));
    }
}
