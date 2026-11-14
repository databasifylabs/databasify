<?php

declare(strict_types=1);

namespace App\Actions\Databases\Configuration;

use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\RetuneDatabaseJob;
use App\Models\Database;

final readonly class RetuneDatabase
{
    public function handle(Database $database): void
    {
        $database->ensureIdle();
        $database->markBusy(DatabaseStatus::Updating);
        dispatch(new RetuneDatabaseJob($database));
    }
}
