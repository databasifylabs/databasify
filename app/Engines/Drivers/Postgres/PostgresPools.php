<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Postgres;

use App\Engines\Contracts\Pools;
use App\Engines\Data\Pool;
use App\Engines\Payload;
use App\Exceptions\Engines\EngineAlreadyExists;
use App\Exceptions\Engines\EngineNotFound;
use App\Exceptions\Engines\InvalidEngineRequest;

final readonly class PostgresPools implements Pools
{
    private const array RESERVED = ['postgres', 'pgbouncer'];

    private const string POOLS_STATE = 'conf/pools.json';

    public function __construct(
        private PostgresDriver $driver,
        private PostgresAccounts $accounts,
    ) {}

    public function list(): array
    {
        $poolsJson = $this->driver->getFile(self::POOLS_STATE);

        if ($poolsJson === null) {
            return [];
        }

        return array_map(Pool::fromPayload(...), Payload::decode('{"pools":'.$poolsJson.'}')->objects('pools'));
    }

    public function create(Pool $pool): void
    {
        $saved = $this->list();

        if (in_array($pool->name, self::RESERVED, true)) {
            throw new InvalidEngineRequest("{$pool->name} is reserved");
        }

        if ($this->indexOf($saved, $pool->name) !== null) {
            throw new EngineAlreadyExists("pool {$pool->name} already exists");
        }

        $this->requireDatabase($pool->database);
        $this->save([...$saved, $pool]);
    }

    public function update(Pool $pool): void
    {
        $saved = $this->list();

        if ($this->indexOf($saved, $pool->name) === null) {
            throw new EngineNotFound("pool {$pool->name} does not exist");
        }

        $this->requireDatabase($pool->database);
        $this->save(array_map(fn (Pool $existing): Pool => $existing->name === $pool->name ? $pool : $existing, $saved));
    }

    public function delete(string $name): void
    {
        $saved = $this->list();
        $index = $this->indexOf($saved, $name) ?? throw new EngineNotFound("pool {$name} does not exist");
        unset($saved[$index]);
        $this->save(array_values($saved));
    }

    /**
     * @param  list<Pool>  $pools
     */
    private function save(array $pools): void
    {
        $this->driver->run('engines.postgres.pools.save', [
            'poolsJson' => json_encode(array_map(fn (Pool $pool): array => $pool->toPayload(), $pools), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'poolsIni' => collect($pools)
                ->map(fn (Pool $pool): string => "{$pool->name} = host=127.0.0.1 port=".PostgresFiles::CLIENT_PORT." dbname={$pool->database} pool_size={$pool->size} pool_mode={$pool->mode}\n")
                ->implode(''),
        ]);
    }

    private function requireDatabase(string $database): void
    {
        if (! in_array($database, $this->accounts->list()->databases, true)) {
            throw new EngineNotFound("database {$database} does not exist");
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
