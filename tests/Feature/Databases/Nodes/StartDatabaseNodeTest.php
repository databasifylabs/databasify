<?php

declare(strict_types=1);

use App\Actions\Databases\Nodes\StartDatabaseNode;
use App\Enums\Databases\DatabaseMode;
use App\Exceptions\Ssh\RemoteScriptFailed;
use App\Services\Ssh\Ssh;

it('starts a node and waits until it serves as expected', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $seed = $database->seed();
    $ssh = fakeSsh();

    resolve(StartDatabaseNode::class)->handle($database, $seed, expectPrimary: true);

    expect($ssh->runs)->toHaveCount(2)
        ->and($ssh->scriptAt(0))->toContain("processes='databasify-valkey'")->toContain('databasify ctl restart "$process"')
        ->and($ssh->scriptAt(1))->toContain("grep -q '^role:master'")
        ->and($ssh->runs[1]['timeout'])->toBe(Ssh::LONG_TIMEOUT)
        ->and($database->serverLogs()->pluck('name')->all())->toBe(["{$seed->server->name}: starting", "{$seed->server->name}: waiting until ready"]);
});

it('does not wait for a node that failed to start', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $ssh = fakeSsh()->respond('no space left on device', 1);

    expect(fn () => resolve(StartDatabaseNode::class)->handle($database, $database->seed(), expectPrimary: true))
        ->toThrow(RemoteScriptFailed::class)
        ->and($ssh->runs)->toHaveCount(1);
});

it('adopts a joining node on the primary once it runs, skipping hooks the engine does not need', function () {
    $database = idleDatabase('mysql', 2);
    [$seed, $standby] = $database->nodes()->with('server')->get()->all();
    $ssh = fakeSsh();

    resolve(StartDatabaseNode::class)->handle($database, $standby, expectPrimary: false, joining: true);

    expect(collect($ssh->runs)->pluck('server')->all())->toBe([$standby->server->name, $seed->server->name, $standby->server->name])
        ->and($ssh->scriptAt(0))->toContain('databasify ctl restart mysql')->not->toContain('dba.create_cluster')
        ->and($ssh->scriptAt(1))->toContain("dba.get_cluster().add_instance('{$standby->privateIp()}:25060', {'recoveryMethod': 'clone', 'localAddress': '{$standby->privateIp()}:33061'\$(group_allowlist_option)})");
});

it('creates the cluster on the seed it bootstraps', function () {
    $database = idleDatabase('mysql', 2);
    $seed = $database->seed();
    $ssh = fakeSsh();

    resolve(StartDatabaseNode::class)->handle($database, $seed, expectPrimary: true, bootstrapsCluster: true);

    expect($ssh->runs)->toHaveCount(2)
        ->and($ssh->scriptAt(0))->toContain("dba.create_cluster('main', {'communicationStack': 'XCOM', 'memberSslMode': 'REQUIRED', 'expelTimeout': 0, 'localAddress': '{$seed->privateIp()}:33061'\$(group_allowlist_option)})");
});

it('admits a joining postgres member into etcd as a learner and promotes it once started', function () {
    $database = idleDatabase('postgres', 2);
    [$seed, $standby] = $database->nodes()->with('server')->get()->all();
    $ssh = fakeSsh();

    resolve(StartDatabaseNode::class)->handle($database, $standby, expectPrimary: false, joining: true);

    expect(collect($ssh->runs)->pluck('server')->all())->toBe([$seed->server->name, $standby->server->name, $seed->server->name, $standby->server->name])
        ->and($ssh->scriptAt(0))->toContain("etcd_ctl member add {$standby->privateIp()} --peer-urls=\"\$peer\" --learner")
        ->and($ssh->scriptAt(2))->toContain('etcd_ctl member promote "$learner"')->toContain("peer=http://{$standby->privateIp()}:2380");
});
