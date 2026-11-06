<?php

declare(strict_types=1);

namespace App\Actions\Databases\Lifecycle;

use App\Jobs\Databases\RefreshDatabaseStatusJob;
use App\Models\Database;

final readonly class QueueStatusRefresh
{
    public function handle(Database $database): void
    {
        dispatch(new RefreshDatabaseStatusJob($database));
    }
}
