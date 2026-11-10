<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\NodeRole;

it('points the database at its members and each read-only node at itself on the same port', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $seed = $database->seed();
    $reader = withReadOnlyNode($database);
    $reader->update(['role' => NodeRole::Primary]);

    expect($database->endpoints()->toArray())->toBe([
        'public' => ['host' => $seed->server->public_ip, 'port' => 25060],
        'vpc' => ['host' => $seed->privateIp(), 'port' => 25060],
        'standby' => null,
    ])->and($reader->endpoints()->toArray())->toBe([
        'public' => ['host' => $reader->server->public_ip, 'port' => 25060],
        'vpc' => ['host' => $reader->privateIp(), 'port' => 25060],
        'standby' => null,
    ])->and($database->environment($database->endpoints()))->not->toHaveKey('DB_READ_HOST');
});

it('uses the hostnames for public and private valkey endpoints', function () {
    $database = withZone(idleDatabase('valkey', 1, DatabaseMode::Single, 'valkey'));
    withReadOnlyNode($database, ['name' => 'reports']);

    expect($database->environment($database->endpoints()))
        ->toMatchArray(['REDIS_HOST' => 'valkey.db.example.com', 'REDIS_PORT' => '25061'])
        ->not->toHaveKey('REDIS_READ_HOST')
        ->not->toHaveKey('REDIS_TLS_CA_B64')
        ->and($database->endpoints()->vpc->host)->toBe('private-valkey.db.example.com');
});
