<?php

declare(strict_types=1);

namespace App\Actions\Databases\Configuration;

use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\ApplySettingsJob;
use App\Models\Database;

final readonly class UpdateDatabaseConfig
{
    /**
     * @param  array<string, string>  $config
     */
    public function handle(Database $database, array $config): void
    {
        $database->ensureIdle();
        $database->markBusyWith(DatabaseStatus::Updating, fn (Database $locked) => $locked->updateConfig($config));
        dispatch(new ApplySettingsJob($database));
    }
}
