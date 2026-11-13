<?php

declare(strict_types=1);

use App\Engines\Contracts\Pools;
use App\Engines\Data\Pool;
use App\Exceptions\Engines\EngineAlreadyExists;
use App\Exceptions\Engines\EngineNotFound;
use App\Exceptions\Engines\InvalidEngineRequest;

beforeEach(function () {
    $this->scripts = fakeScripts()
        ->respond('postgres/pools/list', ['pools' => [['name' => 'web', 'database' => 'shop', 'size' => 10, 'mode' => 'transaction']]])
        ->respond('postgres/accounts/list', ['users' => [], 'databases' => ['shop', 'blog']]);
    $this->node = engineNode();
});

it('lists the saved pools', function () {
    $pools = $this->node->driver()->pools()->list();

    expect($pools)->toHaveCount(1)->and($pools[0]->name)->toBe('web');
});

it('adds, changes and removes pools in the saved list', function () {
    $this->node->driver()->pools()->create(new Pool('api', 'blog', 5, 'session'));

    $this->scripts->assertRan('postgres/pools/save', fn (string $script, array $env): bool => json_decode($env['POOLS'], true) === [
        ['name' => 'web', 'database' => 'shop', 'size' => 10, 'mode' => 'transaction'],
        ['name' => 'api', 'database' => 'blog', 'size' => 5, 'mode' => 'session'],
    ] && str_contains($script, 'kill -s HUP databasify-pgbouncer'));

    $this->node->driver()->pools()->update(new Pool('web', 'blog', 20, 'transaction'));
    $this->node->driver()->pools()->delete('web');

    $this->scripts->assertRan('postgres/pools/save', fn (string $script, array $env): bool => json_decode($env['POOLS'], true) === [['name' => 'web', 'database' => 'blog', 'size' => 20, 'mode' => 'transaction']])
        ->assertRan('postgres/pools/save', fn (string $script, array $env): bool => json_decode($env['POOLS'], true) === []);
});

it('refuses reserved, duplicate and unknown pools with the Go wording', function (Closure $call, string $exception, string $message) {
    expect(fn () => $call($this->node->driver()->pools()))->toThrow($exception, $message);
})->with([
    'reserved' => [fn (Pools $pools) => $pools->create(new Pool('pgbouncer', 'shop', 5, 'session')), InvalidEngineRequest::class, 'pgbouncer is reserved'],
    'duplicate' => [fn (Pools $pools) => $pools->create(new Pool('web', 'shop', 5, 'session')), EngineAlreadyExists::class, 'pool web already exists'],
    'missing database' => [fn (Pools $pools) => $pools->create(new Pool('api', 'nope', 5, 'session')), EngineNotFound::class, 'database nope does not exist'],
    'missing pool' => [fn (Pools $pools) => $pools->delete('ghost'), EngineNotFound::class, 'pool ghost does not exist'],
]);
