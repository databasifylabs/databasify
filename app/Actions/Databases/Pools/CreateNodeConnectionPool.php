<?php

declare(strict_types=1);

namespace App\Actions\Databases\Pools;

use App\DTOs\Databases\NewConnectionPool;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Engines\Feature;
use App\Jobs\Databases\ApplyNodeConnectionPoolsJob;
use App\Models\ConnectionPool;
use App\Models\DatabaseNode;
use Illuminate\Validation\ValidationException;

final readonly class CreateNodeConnectionPool
{
    public function handle(DatabaseNode $node, NewConnectionPool $data): ConnectionPool
    {
        $node->ensureReadOnly();
        $node->database->ensureRunsSupportedSdk();

        if (! $node->database->offers(Feature::Pools)) {
            throw ValidationException::withMessages(['name' => "{$node->database->engine()->label} databases have no connection pools."]);
        }

        $pool = $node->markBusyWith(DatabaseStatus::Updating, fn (DatabaseNode $locked): ConnectionPool => $locked->connectionPools()->create([...$data->toRow(), 'database_id' => $locked->database_id]));
        dispatch(new ApplyNodeConnectionPoolsJob($node));

        return $pool;
    }
}
