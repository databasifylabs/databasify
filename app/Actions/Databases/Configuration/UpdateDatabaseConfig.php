<?php

declare(strict_types=1);

namespace App\Actions\Databases\Configuration;

use App\Actions\Databases\Flows\StartFlow;
use App\Engines\Flows\ApplySettingsFlow;
use App\Engines\Flows\RollingSteps;
use App\Models\Database;

final readonly class UpdateDatabaseConfig
{
    public function __construct(
        private StartFlow $startFlow,
        private ApplySettingsFlow $flow,
    ) {}

    /**
     * @param  array<string, string>  $config
     */
    public function handle(Database $database, array $config): void
    {
        $database->ensureIdle();
        $database->updateConfig($config);

        $this->startFlow->handle($database, $this->flow, RollingSteps::contextFor($database));
    }
}
