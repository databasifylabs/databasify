<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\NodeRole;

it('enters through the primary member, then any healthy member, then the first member', function () {
    $database = idleDatabase('postgres', 3);
    [$first, $second, $third] = $database->nodes()->with('server')->get()->all();
    withReadOnlyNode($database, ['role' => NodeRole::Primary]);

    markRoles($database, NodeRole::Unknown, NodeRole::Replica, NodeRole::Primary);
    expect($database->endpoints()->vpc->host)->toBe($third->privateIp());

    markRoles($database, NodeRole::Unknown, NodeRole::Replica, NodeRole::Unknown);
    expect($database->endpoints()->vpc->host)->toBe($second->privateIp());

    markRoles($database, NodeRole::Unknown, NodeRole::Unknown, NodeRole::Unknown);
    expect($database->endpoints())->public->host->toBe($first->server->public_ip)->public->port->toBe(25060);
});

it('names a read-only node endpoint only when it has hostnames under the database domain', function () {
    $database = withZone(idleDatabase('valkey', 1));
    $named = withReadOnlyNode($database, ['name' => 'reports']);
    $unnamed = withReadOnlyNode($database);
    $unzoned = withReadOnlyNode(idleDatabase('valkey', 1, name: 'other'), ['name' => 'other-reports']);

    expect($named->endpoints())->public->host->toBe('reports.db.example.com')->vpc->host->toBe('private-reports.db.example.com')
        ->and($unnamed->endpoints())->public->host->toBe($unnamed->server->public_ip)
        ->and($unzoned->endpoints())->public->host->toBe($unzoned->server->public_ip);
});

it('sends standby clients to a standby on the same port, or to the replica hostnames under a domain', function () {
    $database = idleDatabase('mysql', 3, name: 'app-db');
    markRoles($database, NodeRole::Unknown, NodeRole::Primary, NodeRole::Replica);
    [, , $standby] = $database->nodes()->with('server')->get()->all();

    expect($database->endpoints()->standby?->toArray())->toBe([
        'public' => ['host' => $standby->server->public_ip, 'port' => 25060],
        'vpc' => ['host' => $standby->privateIp(), 'port' => 25060],
    ])
        ->and(withZone($database)->endpoints()->standby?->toArray())->toBe([
            'public' => ['host' => 'replica-app-db.db.example.com', 'port' => 25060],
            'vpc' => ['host' => 'private-replica-app-db.db.example.com', 'port' => 25060],
        ]);
});

it('offers no standby endpoint without standbys or for a read-only node', function () {
    $database = withZone(idleDatabase('valkey', 1, DatabaseMode::Single));
    $reader = withReadOnlyNode($database, ['name' => 'reports']);

    expect($database->endpoints()->standby)->toBeNull()
        ->and($reader->endpoints()->standby)->toBeNull()
        ->and($database->endpoints()->toArray())->toHaveKey('standby', null);
});
