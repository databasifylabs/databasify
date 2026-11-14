<?php

declare(strict_types=1);

use App\Engines\Drivers\Postgres\PostgresDriver;
use App\Enums\Engines\Feature;
use App\Exceptions\Engines\InvalidEngineRequest;
use App\Support\Databases\ClientPorts;

it('builds the registered driver for a node', function () {
    $node = engineNode();

    $driver = $node->driver();

    expect($driver)->toBeInstanceOf(PostgresDriver::class)
        ->and($driver->node)->toBe($node);
});

it('declares postgres facts without a node', function () {
    $facts = PostgresDriver::facts('18');

    expect($facts->features)->toBe([Feature::Accounts, Feature::Archive, Feature::Migration, Feature::Pools])
        ->and($facts->reservedUsers)->toBe(['postgres', 'replicator', 'pgbouncer', 'patroni', 'admin'])
        ->and($facts->ports->client)->toBe(ClientPorts::CLIENT)
        ->and($facts->ports->pool)->toBe(ClientPorts::POOL);
});

it('keeps a quoted or multiline setting as one value', function () {
    $scripts = fakeScripts();
    fakeSsh()->respondToEngine('file get', ['contentBase64' => '', 'exists' => false]);
    $request = goFixture('configure-standalone.json')['request'];
    $request['settings'] = ['timezone' => "UTC'\n#log_statement = 'all'"];

    expect(fn () => engineNode()->driver()->nodes()->configure(nodeConfigFromFixture($request)))->toThrow(InvalidEngineRequest::class);
    expect($scripts->ran())->toBe([]);
});
