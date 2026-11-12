<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Postgres;

use App\Contracts\Engines\EngineScripts;
use App\Engines\Data\Pool;
use App\Engines\Requests\PoolCreate;
use App\Engines\Requests\PoolDelete;
use App\Engines\Requests\PoolUpdate;
use App\Engines\Responses\Done;
use App\Engines\Responses\PoolList;
use App\Exceptions\Engines\EngineAlreadyExists;
use App\Exceptions\Engines\EngineNotFound;
use App\Exceptions\Engines\InvalidEngineRequest;
use App\Models\DatabaseNode;

final readonly class Pools
{
    private const array RESERVED = ['postgres', 'pgbouncer'];

    public function __construct(
        private EngineScripts $scripts,
        private Accounts $accounts,
    ) {}

    public function list(DatabaseNode $node): PoolList
    {
        return PoolList::fromPayload($this->scripts->run($node, 'postgres/pools/list', $this->accounts->dataFor($node))->json());
    }

    public function create(DatabaseNode $node, PoolCreate $request): Done
    {
        $saved = $this->list($node)->pools;

        if (in_array($request->pool->name, self::RESERVED, true)) {
            throw new InvalidEngineRequest('invalid_request', "{$request->pool->name} is reserved");
        }

        if ($this->indexOf($saved, $request->pool->name) !== null) {
            throw new EngineAlreadyExists('already_exists', "pool {$request->pool->name} already exists");
        }

        $this->requireDatabase($node, $request->pool->database);

        return $this->save($node, [...$saved, $request->pool]);
    }

    public function update(DatabaseNode $node, PoolUpdate $request): Done
    {
        $saved = $this->list($node)->pools;

        if ($this->indexOf($saved, $request->pool->name) === null) {
            throw new EngineNotFound('not_found', "pool {$request->pool->name} does not exist");
        }

        $this->requireDatabase($node, $request->pool->database);

        return $this->save($node, array_map(fn (Pool $pool): Pool => $pool->name === $request->pool->name ? $request->pool : $pool, $saved));
    }

    public function delete(DatabaseNode $node, PoolDelete $request): Done
    {
        $saved = $this->list($node)->pools;
        $index = $this->indexOf($saved, $request->name) ?? throw new EngineNotFound('not_found', "pool {$request->name} does not exist");
        unset($saved[$index]);

        return $this->save($node, array_values($saved));
    }

    /**
     * @param  list<Pool>  $pools
     */
    private function save(DatabaseNode $node, array $pools): Done
    {
        $this->scripts->run($node, 'postgres/pools/save', $this->accounts->dataFor($node), env: ['POOLS' => json_encode(array_map(fn (Pool $pool): array => $pool->toPayload(), $pools), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);

        return new Done;
    }

    private function requireDatabase(DatabaseNode $node, string $database): void
    {
        if (! in_array($database, $this->accounts->list($node)->databases, true)) {
            throw new EngineNotFound('not_found', "database {$database} does not exist");
        }
    }

    /**
     * @param  list<Pool>  $pools
     */
    private function indexOf(array $pools, string $name): ?int
    {
        foreach ($pools as $index => $pool) {
            if ($pool->name === $name) {
                return $index;
            }
        }

        return null;
    }
}
