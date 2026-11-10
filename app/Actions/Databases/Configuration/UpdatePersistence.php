<?php

declare(strict_types=1);

namespace App\Actions\Databases\Configuration;

use App\Actions\Databases\Flows\StartFlow;
use App\Engines\Flows\ApplySettingsFlow;
use App\Engines\Flows\RollingSteps;
use App\Enums\Databases\Persistence;
use App\Models\Database;
use Illuminate\Validation\ValidationException;

final readonly class UpdatePersistence
{
    private const string APPEND_ONLY = 'appendonly';

    public function __construct(
        private StartFlow $startFlow,
        private ApplySettingsFlow $flow,
    ) {}

    public function handle(Database $database, Persistence $persistence): void
    {
        $database->ensureIdle();
        $engine = $database->engine();

        if (! $engine->persistenceChoice) {
            throw ValidationException::withMessages(['persistence' => "{$engine->label} always keeps its data on disk."]);
        }

        $config = array_diff_key($database->settings->config, [self::APPEND_ONLY => true]);
        $database->updatePersistence($persistence);
        $database->updateConfig($persistence === Persistence::Off ? [...$config, self::APPEND_ONLY => 'no'] : $config);

        $this->startFlow->handle($database, $this->flow, RollingSteps::contextFor($database));
    }
}
