<?php

declare(strict_types=1);

namespace App\Actions\Databases\Configuration;

use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\Persistence;
use App\Jobs\Databases\ApplySettingsJob;
use App\Models\Database;
use Illuminate\Validation\ValidationException;

final readonly class UpdatePersistence
{
    private const string APPEND_ONLY = 'appendonly';

    public function handle(Database $database, Persistence $persistence): void
    {
        $database->ensureIdle();
        $engine = $database->engine();

        if (! $engine->persistenceChoice) {
            throw ValidationException::withMessages(['persistence' => "{$engine->label} always keeps its data on disk."]);
        }

        $database->markBusyWith(DatabaseStatus::Updating, function (Database $locked) use ($persistence): void {
            $config = array_diff_key($locked->settings->config, [self::APPEND_ONLY => true]);
            $locked->updatePersistence($persistence);
            $locked->updateConfig($persistence === Persistence::Off ? [...$config, self::APPEND_ONLY => 'no'] : $config);
        });
        dispatch(new ApplySettingsJob($database));
    }
}
