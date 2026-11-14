<?php

declare(strict_types=1);

namespace App\Actions\Databases\Pools;

use App\DTOs\Databases\NewConnectionPool;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Engines\Feature;
use App\Jobs\Databases\ApplyConnectionPoolsJob;
use App\Models\ConnectionPool;
use App\Models\Database;
use Illuminate\Validation\ValidationException;

final readonly class CreateConnectionPool
{
    public function handle(Database $database, NewConnectionPool $data): ConnectionPool
    {
        $database->ensureIdle();

        if (! $database->offers(Feature::Pools)) {
            throw ValidationException::withMessages(['name' => "{$database->engine()->label} databases have no connection pools."]);
        }

        $pool = $database->markBusyWith(DatabaseStatus::Updating, fn (Database $locked): ConnectionPool => $locked->connectionPools()->create($data->toRow()));
        dispatch(new ApplyConnectionPoolsJob($database));

        return $pool;
    }
}
