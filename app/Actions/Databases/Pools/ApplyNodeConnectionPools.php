<?php

declare(strict_types=1);

namespace App\Actions\Databases\Pools;

use App\Engines\Data\Pool;
use App\Models\ConnectionPool;
use App\Models\DatabaseNode;

final readonly class ApplyNodeConnectionPools
{
    public function __construct(private SyncNodePools $sync) {}

    public function handle(DatabaseNode $node): void
    {
        $this->sync->handle($node, $node->connectionPools()->get()->map(fn (ConnectionPool $pool): Pool => $pool->toPool())->keyBy(fn (Pool $pool): string => $pool->name)->all());
        $node->markReady();
    }
}
