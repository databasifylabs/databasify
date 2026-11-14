<?php

declare(strict_types=1);

namespace App\Actions\Databases\Pools;

use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\ApplyConnectionPoolsJob;
use App\Models\ConnectionPool;

final readonly class DeleteConnectionPool
{
    public function handle(ConnectionPool $pool): void
    {
        $database = $pool->database;
        $database->ensureIdle();

        $database->markBusyWith(DatabaseStatus::Updating, fn () => $pool->delete());
        dispatch(new ApplyConnectionPoolsJob($database));
    }
}
