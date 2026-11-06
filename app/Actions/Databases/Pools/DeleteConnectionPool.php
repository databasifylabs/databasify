<?php

declare(strict_types=1);

namespace App\Actions\Databases\Pools;

use App\Jobs\Databases\ApplyConnectionPoolsJob;
use App\Models\ConnectionPool;
use Illuminate\Support\Facades\DB;

final readonly class DeleteConnectionPool
{
    public function handle(ConnectionPool $pool): void
    {
        $database = $pool->database;
        $database->ensureIdle();

        DB::transaction(function () use ($pool, $database): void {
            $pool->delete();
            $database->startOperation("Remove connection pool {$pool->name}");
        });
        dispatch(new ApplyConnectionPoolsJob($database));
    }
}
