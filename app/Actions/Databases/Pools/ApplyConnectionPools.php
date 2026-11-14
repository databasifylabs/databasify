<?php

declare(strict_types=1);

namespace App\Actions\Databases\Pools;

use App\Engines\Data\Pool;
use App\Models\ConnectionPool;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\NodeRunner;

final readonly class ApplyConnectionPools
{
    public function __construct(
        private NodeRunner $nodeRunner,
    ) {}

    public function handle(Database $database): void
    {
        $wanted = $database->connectionPools()->get()->map(fn (ConnectionPool $pool): Pool => $pool->toPool())->keyBy(fn (Pool $pool): string => $pool->name)->all();
        $nodes = $database->nodesWithServers();

        $this->nodeRunner->each(
            $nodes,
            function (DatabaseNode $node) use ($wanted): void {
                $current = collect($node->driver()->pools()->list())->keyBy('name');
                $current->keys()->diff(array_keys($wanted))->each(fn (string $name) => $node->driver()->pools()->delete($name));

                foreach ($wanted as $name => $pool) {
                    $existing = $current->get($name);

                    if ($existing == $pool) {
                        continue;
                    }

                    $existing === null ? $node->driver()->pools()->create($pool) : $node->driver()->pools()->update($pool);
                }
            },
            "updating connection pools on {$nodes->count()} nodes",
        );
        $database->markReady();
    }
}
