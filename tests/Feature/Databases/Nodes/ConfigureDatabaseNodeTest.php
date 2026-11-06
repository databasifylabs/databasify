<?php

declare(strict_types=1);

use App\Actions\Databases\Nodes\ConfigureDatabaseNode;

it('writes the configuration of each node on its own server', function () {
    $database = idleDatabase('valkey', 2);
    [$seed, $standby] = $database->nodes()->get()->all();
    $ssh = fakeSsh();

    resolve(ConfigureDatabaseNode::class)->handle($database, $seed);
    resolve(ConfigureDatabaseNode::class)->handle($database, $standby);

    expect(collect($ssh->runs)->pluck('server')->all())->toBe([$seed->server->name, $standby->server->name])
        ->and(fileWrittenIn($ssh->scriptAt(0), '/srv/valkey/conf/valkey.conf'))->not->toContain('replicaof')
        ->and(fileWrittenIn($ssh->scriptAt(1), '/srv/valkey/conf/valkey.conf'))->toContain("replicaof {$seed->privateIp()} 6379\n")
        ->and($standby->server->serverLogs()->sole())->name->toBe("{$standby->server->name}: writing configuration")->database_id->toBe($database->id);
});
