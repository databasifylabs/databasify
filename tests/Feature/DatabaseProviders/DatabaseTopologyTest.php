<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;

it('lists every node or only members by private address', function () {
    $database = idleDatabase('valkey', 2);
    $reader = withReadOnlyNode($database);
    [$seed, $standby] = $database->nodes()->members()->get()->all();
    $reader->server->update(['private_ip' => null]);

    expect($database->hosts())->toBe("{$seed->privateIp()},{$standby->privateIp()},{$reader->server->public_ip}")
        ->and($database->memberHosts())->toBe("{$seed->privateIp()},{$standby->privateIp()}")
        ->and($database->size())->toBe(3);
});

it('needs a strict majority of nodes for quorum', function (int $members, bool $withReader, int $quorum) {
    $database = idleDatabase('valkey', $members, name: "quorum-{$members}");

    if ($withReader) {
        withReadOnlyNode($database);
    }

    expect($database->provider()->membershipVariables()['quorum'])->toBe($quorum);
})->with([
    'one node' => [1, false, 1],
    'two nodes' => [2, false, 2],
    'three nodes' => [3, false, 2],
    'four nodes' => [3, true, 3],
]);

it('lists sorted unique peer addresses of every node', function () {
    $database = idleDatabase('mysql', 2);
    $reader = withReadOnlyNode($database);
    $reader->server->update(['private_ip' => $reader->server->public_ip]);
    $expected = $database->nodes()->with('server')->get()->flatMap(fn ($node) => [$node->server->public_ip, $node->privateIp()])->unique()->sort()->values()->all();

    expect($database->peerAddresses())->toBe($expected)->toHaveCount(5);
});

it('asks for certificates under every database hostname and member addresses', function () {
    $database = withZone(idleDatabase('valkey', 1, DatabaseMode::Single, 'cache'));
    $reader = withReadOnlyNode($database, ['name' => 'reports']);
    $seed = $database->seed();

    expect($database->certificateNames())->toBe(['cache.db.example.com', 'private-cache.db.example.com', 'replica-cache.db.example.com', 'private-replica-cache.db.example.com', $seed->server->public_ip, $seed->privateIp()])
        ->and($reader->certificateNames())->toBe(['reports.db.example.com', 'private-reports.db.example.com', $reader->server->public_ip, $reader->privateIp()]);
});

it('runs a database with more than one node as a cluster', function () {
    $single = idleDatabase('valkey', 1, DatabaseMode::Single, 'single');
    $pair = idleDatabase('valkey', 2, name: 'pair');

    expect($single->isClustered())->toBeFalse()
        ->and($pair->isClustered())->toBeTrue();

    withReadOnlyNode($single);

    expect($single->isClustered())->toBeTrue();
});

it('makes fresh 32 character passwords from upper and lower case letters and digits', function () {
    $password = new_password();

    expect($password)->toMatch('/^[A-Za-z0-9]{32}$/')
        ->toMatch('/[A-Z]/')
        ->toMatch('/[a-z]/')
        ->toMatch('/[0-9]/')
        ->and(new_password())->not->toBe($password);
});
