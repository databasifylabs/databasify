<?php

declare(strict_types=1);

use App\Engines\Data\Member;

beforeEach(function () {
    config(['databasify.php_engines' => ['postgres']]);
    $this->scripts = fakeScripts();
    $this->node = engineNode();
    $this->node->database->update(['version_line' => '18']);
    $this->node = $this->node->fresh();
});

it('switches over and forces a quorum with the Go refusals', function () {
    $this->node->driver()->cluster()->failover('10.0.0.2');
    $this->node->driver()->cluster()->forceQuorum(othersDown: false);

    $this->scripts->assertRan('postgres/cluster/failover', fn (string $script, array $env): bool => $env === ['TARGET' => '10.0.0.2'] && str_contains($script, 'switchover --candidate "$TARGET" --force'))
        ->assertRan('postgres/cluster/force-quorum', fn (string $script, array $env): bool => $env === ['OTHERS_DOWN' => 'false']
            && str_contains($script, 'etcd has no majority and the other members may still be running')
            && str_contains($script, 'ETCD_FORCE_NEW_CLUSTER=true'));
});

it('points Patroni at the current etcd members', function () {
    $this->node->driver()->cluster()->membership([new Member('10.0.0.1', 1, true, 2, 4096, 80), new Member('10.0.0.3', 2, true, 2, 4096, 80)]);

    $this->scripts->assertRan('postgres/cluster/membership', fn (string $script, array $env): bool => $env === ['HOSTS' => '10.0.0.1:2379,10.0.0.3:2379']);
});

it('reads the WAL position and the quorum', function () {
    $this->scripts->respond('postgres/cluster/position', ['position' => 50331648])->respond('postgres/cluster/quorum', ['total' => 3, 'online' => 2]);

    $position = $this->node->driver()->cluster()->position();
    $quorum = $this->node->driver()->cluster()->quorum();

    expect($position)->toBe(50331648)->and($quorum->total)->toBe(3)->and($quorum->online)->toBe(2);
    $this->scripts->assertRan('postgres/cluster/position', fn (string $script, array $env): bool => $env === ['LINE' => '18'] && str_contains($script, 'pg_controldata'));
});

it('reads an unknown position as null', function () {
    $this->scripts->respond('postgres/cluster/position', ['position' => null]);

    expect($this->node->driver()->cluster()->position())->toBeNull();
});

it('disables the cluster around a standalone configure', function () {
    $ssh = respondToFileGet(fakeSsh(), 'conf/request.json', json_encode(goFixture('configure-primary.json')['request']));
    respondToFileGet($ssh, 'conf/tuned.json', '{"shared_buffers":"1024MB"}');
    $this->scripts->respond('postgres/nodes/configure', ['changed' => true]);

    $this->node->driver()->cluster()->disable();

    expect($this->scripts->ran())->toBe(['postgres/cluster/disable-stop', 'postgres/nodes/configure', 'postgres/cluster/disable-finish']);
    $this->scripts->assertRan('postgres/nodes/configure', fn (string $script, array $env): bool => $env['CLUSTERED'] === 'false' && $env['READ_ONLY'] === 'false');
});

it('rejoins as a task and forgets an etcd member', function () {
    $operation = $this->node->driver()->cluster()->rejoin('flow.9.1', false);
    $this->node->driver()->cluster()->forget('10.0.0.3');

    expect($operation->key)->toBe('flow.9.1');
    $this->scripts->assertRan('postgres/cluster/rejoin', fn (string $script): bool => str_contains($script, "phase 'starting Patroni'"))
        ->assertRan('postgres/members/forget', fn (string $script, array $env): bool => $env === ['IP' => '10.0.0.3'] && str_contains($script, 'member remove'));
});
