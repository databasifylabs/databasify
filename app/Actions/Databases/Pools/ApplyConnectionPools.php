<?php

declare(strict_types=1);

namespace App\Actions\Databases\Pools;

use App\Engines\Data\Pool;
use App\Models\ConnectionPool;
use App\Models\Database;
use App\Models\DatabaseNode;

final readonly class ApplyConnectionPools
{
    public function __construct(private SyncNodePools $sync) {}

    public function handle(Database $database): void
    {
        $wanted = $database->connectionPools()->get()->map(fn (ConnectionPool $pool): Pool => $pool->toPool())->keyBy(fn (Pool $pool): string => $pool->name)->all();
        $database->nodesWithServers()->members()->each(fn (DatabaseNode $node) => $this->sync->handle($node, $wanted));
        $database->markReady();
    }
}
