<?php

declare(strict_types=1);

namespace App\Actions\Databases\Pools;

use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\ApplyNodeConnectionPoolsJob;
use App\Models\ConnectionPool;
use App\Models\DatabaseNode;

final readonly class DeleteNodeConnectionPool
{
    public function handle(DatabaseNode $node, ConnectionPool $pool): void
    {
        $node->ensureReadOnly();
        $node->database->ensureRunsSupportedSdk();
        $node->markBusyWith(DatabaseStatus::Updating, fn (): ?bool => $pool->delete());
        dispatch(new ApplyNodeConnectionPoolsJob($node));
    }
}
