<?php

declare(strict_types=1);

namespace App\Actions\Databases\Pools;

use App\DTOs\Databases\NewConnectionPool;
use App\Jobs\Databases\ApplyConnectionPoolsJob;
use App\Models\ConnectionPool;
use App\Models\Database;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CreateConnectionPool
{
    public function handle(Database $database, NewConnectionPool $data): ConnectionPool
    {
        $database->ensureIdle();

        if ($database->engine_capabilities?->ports->pool === null) {
            throw ValidationException::withMessages(['name' => "{$database->engine()->label} databases have no connection pools."]);
        }

        $pool = DB::transaction(function () use ($database, $data): ConnectionPool {
            $database->startOperation("Add connection pool {$data->name}");

            return $database->connectionPools()->create($data->toRow());
        });
        dispatch(new ApplyConnectionPoolsJob($database));

        return $pool;
    }
}
