<?php

declare(strict_types=1);

use App\Actions\Databases\Nodes\UpdateDatabaseMembership;
use App\DatabaseProviders\Drivers\Mariadb\Mariadb;

it('updates the membership on every node except the one left out', function () {
    $database = idleDatabase('valkey', 3);
    [$seed, $standby, $joining] = $database->nodes()->get()->all();
    $ssh = fakeSsh();

    resolve(UpdateDatabaseMembership::class)->handle($database, except: $joining);

    expect(collect($ssh->runs)->pluck('server')->all())->toBe([$seed->server->name, $standby->server->name])
        ->and($ssh->scriptAt(0))->toContain('SENTINEL SET main quorum 2')->toContain('CONFIG SET min-replicas-to-write 1')
        ->and($database->serverLogs()->pluck('name'))->toContain("{$standby->server->name}: updating membership");
});

it('points the running mariadb members at the new member list', function () {
    $database = idleDatabase('mariadb', 2);
    $ssh = fakeSsh();

    resolve(UpdateDatabaseMembership::class)->handle($database);

    expect($ssh->scriptAt(0))->toContain("sed -i 's|^wsrep_cluster_address = .*|wsrep_cluster_address = gcomm://".$database->memberHosts()."|' ".Mariadb::CONFIG);
});
