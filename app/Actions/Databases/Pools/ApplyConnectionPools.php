<?php

declare(strict_types=1);

namespace App\Actions\Databases\Pools;

use App\Engines\Data\Pool;
use App\Engines\EngineClient;
use App\Engines\Requests\PoolCreate;
use App\Engines\Requests\PoolDelete;
use App\Engines\Requests\PoolList;
use App\Engines\Requests\PoolUpdate;
use App\Models\ConnectionPool;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\NodeRunner;

final readonly class ApplyConnectionPools
{
    public function __construct(
        private EngineClient $engine,
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
        $log = "{$node->server->name}: updating connection pools";
        $current = collect($this->engine->send($node, new PoolList)->pools)->keyBy('name');

        $current->keys()->diff(array_keys($wanted))->each(fn (string $name) => $this->engine->send($node, new PoolDelete($name), $log));

        foreach ($wanted as $name => $pool) {
            $existing = $current->get($name);

            if ($existing == $pool) {
                continue;
            }

            $this->engine->send($node, $existing === null ? new PoolCreate($pool) : new PoolUpdate($pool), $log);
        }
    }
}
