<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Postgres;

use App\Engines\Contracts\Pools;
use App\Engines\Data\Pool;
use App\Exceptions\Engines\EngineAlreadyExists;
use App\Exceptions\Engines\EngineNotFound;
use App\Exceptions\Engines\InvalidEngineRequest;

final readonly class PostgresPools implements Pools
{
    private const array RESERVED = ['postgres', 'pgbouncer'];

    public function __construct(
        private PostgresDriver $driver,
        private PostgresAccounts $accounts,
    ) {}

    public function list(): array
    {
        return array_map(Pool::fromPayload(...), $this->driver->read('engines.postgres.pools.list', $this->driver->data())->objects('pools'));
    }

    public function create(Pool $pool): void
    {
        $saved = $this->list();

        if (in_array($pool->name, self::RESERVED, true)) {
            throw new InvalidEngineRequest('invalid_request', "{$pool->name} is reserved");
        }

        if ($this->indexOf($saved, $pool->name) !== null) {
            throw new EngineAlreadyExists('already_exists', "pool {$pool->name} already exists");
        }

        $this->requireDatabase($pool->database);
        $this->save([...$saved, $pool]);
    }

    public function update(Pool $pool): void
    {
        $saved = $this->list();

        if ($this->indexOf($saved, $pool->name) === null) {
            throw new EngineNotFound('not_found', "pool {$pool->name} does not exist");
        }

        $this->requireDatabase($pool->database);
        $this->save(array_map(fn (Pool $existing): Pool => $existing->name === $pool->name ? $pool : $existing, $saved));
    }

    public function delete(string $name): void
    {
        $saved = $this->list();
        $index = $this->indexOf($saved, $name) ?? throw new EngineNotFound('not_found', "pool {$name} does not exist");
        unset($saved[$index]);
        $this->save(array_values($saved));
    }

    /**
     * @param  list<Pool>  $pools
     */
    private function save(array $pools): void
    {
        $this->driver->run('engines.postgres.pools.save', $this->driver->data(), env: ['POOLS' => json_encode(array_map(fn (Pool $pool): array => $pool->toPayload(), $pools), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
    }

    private function requireDatabase(string $database): void
    {
        if (! in_array($database, $this->accounts->list()->databases, true)) {
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
