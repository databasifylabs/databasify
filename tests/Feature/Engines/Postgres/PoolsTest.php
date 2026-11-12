<?php

declare(strict_types=1);

use App\Engines\Data\Pool;
use App\Engines\EngineClient;
use App\Engines\Requests\PoolCreate;
use App\Engines\Requests\PoolDelete;
use App\Engines\Requests\PoolList;
use App\Engines\Requests\PoolUpdate;
use App\Exceptions\Engines\EngineAlreadyExists;
use App\Exceptions\Engines\EngineNotFound;
use App\Exceptions\Engines\InvalidEngineRequest;

beforeEach(function () {
    config(['databasify.php_engines' => ['postgres']]);
    $this->scripts = fakeScripts()
        ->respond('postgres/pools/list', ['pools' => [['name' => 'web', 'database' => 'shop', 'size' => 10, 'mode' => 'transaction']]])
        ->respond('postgres/accounts/list', ['users' => [], 'databases' => ['shop', 'blog']]);
    $this->node = engineNode();
});

it('lists the saved pools', function () {
    $pools = resolve(EngineClient::class)->send($this->node, new PoolList);

    expect($pools->pools)->toHaveCount(1)->and($pools->pools[0]->name)->toBe('web');
});

it('adds, changes and removes pools in the saved list', function () {
    $client = resolve(EngineClient::class);
    $client->send($this->node, new PoolCreate(new Pool('api', 'blog', 5, 'session')));

    $this->scripts->assertRan('postgres/pools/save', fn (string $script, array $env): bool => json_decode($env['POOLS'], true) === [
        ['name' => 'web', 'database' => 'shop', 'size' => 10, 'mode' => 'transaction'],
        ['name' => 'api', 'database' => 'blog', 'size' => 5, 'mode' => 'session'],
    ] && str_contains($script, 'kill -s HUP databasify-pgbouncer'));

    $client->send($this->node, new PoolUpdate(new Pool('web', 'blog', 20, 'transaction')));
    $client->send($this->node, new PoolDelete('web'));

    $this->scripts->assertRan('postgres/pools/save', fn (string $script, array $env): bool => json_decode($env['POOLS'], true) === [['name' => 'web', 'database' => 'blog', 'size' => 20, 'mode' => 'transaction']])
        ->assertRan('postgres/pools/save', fn (string $script, array $env): bool => json_decode($env['POOLS'], true) === []);
});

it('refuses reserved, duplicate and unknown pools with the Go wording', function (object $request, string $exception, string $message) {
    expect(fn () => resolve(EngineClient::class)->send($this->node, $request))->toThrow($exception, $message);
})->with([
    'reserved' => [fn () => new PoolCreate(new Pool('pgbouncer', 'shop', 5, 'session')), InvalidEngineRequest::class, 'pgbouncer is reserved'],
    'duplicate' => [fn () => new PoolCreate(new Pool('web', 'shop', 5, 'session')), EngineAlreadyExists::class, 'pool web already exists'],
    'missing database' => [fn () => new PoolCreate(new Pool('api', 'nope', 5, 'session')), EngineNotFound::class, 'database nope does not exist'],
    'missing pool' => [fn () => new PoolDelete('ghost'), EngineNotFound::class, 'pool ghost does not exist'],
]);
