<?php

declare(strict_types=1);

namespace App\Actions\Databases\Upgrades;

use App\Jobs\Databases\CheckDatabaseUpgradeJob;
use App\Models\Database;

final readonly class QueueUpgradeCheck
{
    public function handle(Database $database): void
    {
        dispatch(new CheckDatabaseUpgradeJob($database));
    }
}
