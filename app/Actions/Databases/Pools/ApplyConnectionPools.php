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
        $wanted = $database->connectionPools()->get()->map($this->poolOf(...))->keyBy(fn (Pool $pool): string => $pool->name);
        $nodes = $database->nodesWithServers();

        $this->nodeRunner->each(
            $nodes,
            fn (DatabaseNode $node) => $this->syncOn($node, $wanted->all()),
            "updating connection pools on {$nodes->count()} nodes",
        );
    }

    private function poolOf(ConnectionPool $pool): Pool
    {
        return new Pool($pool->name, $pool->schema, $pool->size, $pool->mode->value);
    }

    /**
     * @param  array<string, Pool>  $wanted
     */
    private function syncOn(DatabaseNode $node, array $wanted): void
    {
        $current = collect($node->driver()->pools()->list())->keyBy('name');

        $current->keys()->diff(array_keys($wanted))->each(fn (string $name) => $node->driver()->pools()->delete($name));

        foreach ($wanted as $name => $pool) {
            $existing = $current->get($name);

            if ($existing == $pool) {
                continue;
            }

            $existing === null ? $node->driver()->pools()->create($pool) : $node->driver()->pools()->update($pool);
        }
    }
}
