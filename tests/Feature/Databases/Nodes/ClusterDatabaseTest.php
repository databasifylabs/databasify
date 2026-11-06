<?php

declare(strict_types=1);

use App\Actions\Databases\Nodes\ClusterDatabase;
use App\Actions\Databases\Nodes\DetachNode;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\NodeRole;

it('turns clustering on for the existing node before the first peer joins', function () {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    $seed = $database->seed();
    $joining = withReadOnlyNode($database);
    $ssh = fakeSsh();

    resolve(ClusterDatabase::class)->handle($database, $joining);

    expect(collect($ssh->runs)->pluck('server')->all())->toBe([$seed->server->name, $seed->server->name])
        ->and(fileWrittenIn($ssh->scriptAt(0), '/srv/postgres/conf/patroni.yml'))->toContain("data_dir: '/srv/postgres/data'")
        ->and(fileWrittenIn($ssh->scriptAt(0), '/srv/postgres/conf/etcd.env'))->toContain("ETCD_INITIAL_CLUSTER={$seed->privateIp()}=http://{$seed->privateIp()}:2380\nETCD_INITIAL_CLUSTER_STATE=new\n")
        ->and($ssh->scriptAt(0))->toContain('install -d -m 700 -o postgres -g postgres "$dir/etcd"')->not->toContain('initdb')
        ->and(json_decode(fileWrittenIn($ssh->scriptAt(0), '/srv/postgres/conf/processes.json'), true, flags: JSON_THROW_ON_ERROR)['processes'])->not->toContain(fn (array $process): bool => $process['name'] === 'databasify-postgres')
        ->and($ssh->scriptAt(1))->toContain("databasify ctl daemon-reload\ndatabasify ctl enable --now databasify-etcd > /dev/null")
        ->toContain('databasify ctl enable --now databasify-patroni > /dev/null');
});

it('lets later etcd members join the members placed before them', function () {
    $database = idleDatabase('postgres', 3);
    [$seed, $second, $third] = $database->nodes()->get()->all();
    $peer = fn ($node): string => "{$node->privateIp()}=http://{$node->privateIp()}:2380";

    expect(renderedFile($database, 'etcd.env', $seed))->toContain("ETCD_INITIAL_CLUSTER={$peer($seed)}\nETCD_INITIAL_CLUSTER_STATE=new\n")
        ->and(renderedFile($database, 'etcd.env', $third))->toContain("ETCD_INITIAL_CLUSTER={$peer($seed)},{$peer($second)},{$peer($third)}\nETCD_INITIAL_CLUSTER_STATE=existing\n");
});

it('turns clustering off on the last node left after its peer is removed', function () {
    $database = idleDatabase('valkey', 2);
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    [$primary, $standby] = $database->nodes()->with('server')->get()->all();
    $ssh = fakeSsh();

    resolve(DetachNode::class)->handle($database, $standby);

    $onPrimary = collect($ssh->runs)->filter(fn (array $run): bool => $run['server'] === $primary->server->name)->pluck('script')->values();
    expect($database->nodes()->count())->toBe(1)
        ->and(fileWrittenIn($onPrimary[$onPrimary->count() - 2], '/srv/valkey/conf/valkey.conf'))->not->toContain('replica-announce-ip')->not->toContain('replicaof')
        ->and($onPrimary->last())->toContain('databasify ctl disable --now databasify-valkey-sentinel')->toContain('REPLICAOF NO ONE');
});

it('keeps clustering on while two nodes remain', function () {
    $database = idleDatabase('valkey', 3);
    markRoles($database, NodeRole::Primary, NodeRole::Replica, NodeRole::Replica);
    $leaving = $database->nodes()->get()[2];
    $ssh = fakeSsh();

    resolve(DetachNode::class)->handle($database, $leaving);

    expect(collect($ssh->runs)->pluck('script')->filter(fn (string $script): bool => str_contains($script, 'REPLICAOF NO ONE')))->toBeEmpty();
});

it('maps the pub/sub buffer limit to a hard and a quarter soft limit', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $database->update(['settings' => $database->settings->withConfig(['pubsub-output-buffer-limit' => '64', 'notify-keyspace-events' => ''])]);

    expect(renderedFile($database->fresh(), 'valkey.conf'))->toContain("client-output-buffer-limit pubsub 64mb 16mb 60\n");
});
