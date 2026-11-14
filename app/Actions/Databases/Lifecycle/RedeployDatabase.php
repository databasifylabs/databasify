<?php

declare(strict_types=1);

namespace App\Actions\Databases\Lifecycle;

use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\UpgradeDatabaseJob;
use App\Models\Database;

final readonly class RedeployDatabase
{
    public function handle(Database $database): void
    {
        $database->markBusyWithReadOnly(DatabaseStatus::Redeploying, fn (): null => null);
        dispatch(new UpgradeDatabaseJob($database));
    }
}
