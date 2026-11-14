<?php

declare(strict_types=1);

use App\Engines\Data\Member;
use App\Exceptions\Engines\EngineBusy;

beforeEach(function () {
    $this->scripts = fakeScripts();
    $this->node = idleDatabase('mongodb', 1)->nodes()->sole();
    respondToFileGet(fakeSsh(), 'state/node.json', json_encode(goFixture('configure-archived.json', 'mongodb')['request']));
});

it('joins the replica set as a task with the saved members', function () {
    $this->node->driver()->cluster()->enable('enable.1');
    $this->node->driver()->cluster()->rejoin('rejoin.1', false);

    $this->scripts->assertRan('engines.mongodb.cluster.enable', fn (string $script, array $env): bool => $env['HOSTS'] === '10.0.0.1:25060,10.0.0.2:25060' && $env['SELF'] === '10.0.0.2:25060' && $env['SEED'] === 'true'
        && str_contains($script, "phase 'syncing'") && str_contains($script, "phase 'voting'") && str_contains($script, 'withMember(configOf(primary.admin), env.SELF)')
        && str_contains($script, 'this member is too stale to catch up and no primary is reachable to copy from; its data stays untouched')
        && ! str_contains(substr($script, (int) strpos($script, 'join_set()')), 'mongo_js root')
        && str_contains($script, 'mongo_js peers'))
        ->assertRan('engines.mongodb.cluster.rejoin', fn (string $script): bool => str_contains($script, "phase 'starting MongoDB'"));
});

it('runs the replica set commands with the Go reconfig rules', function () {
    $this->node->driver()->cluster()->disable();
    $this->node->driver()->cluster()->failover('10.0.0.1');
    $this->node->driver()->cluster()->membership([new Member('10.0.0.1', 1, true, 2, 4096, 80), new Member('10.0.0.2', 2, false, 2, 4096, 80)]);
    $this->node->driver()->cluster()->forget('10.0.0.3');

    $this->scripts->assertRan('engines.mongodb.cluster.disable', fn (string $script): bool => str_contains($script, 'votingAlone(self)') && str_contains($script, 'reconfig(local(), alone, true)'))
        ->assertRan('engines.mongodb.cluster.failover', fn (string $script, array $env): bool => $env['TARGET_HOST'] === '10.0.0.1:25060' && str_contains($script, 'is not a member of the replica set') && str_contains($script, 'replSetStepDown: 60'))
        ->assertRan('engines.mongodb.cluster.membership', fn (string $script, array $env): bool => $env['WANTED'] === '10.0.0.1:25060,10.0.0.2:25060' && $env['NON_VOTING'] === '10.0.0.2:25060')
        ->assertRan('engines.mongodb.members.forget', fn (string $script, array $env): bool => $env['GONE'] === '10.0.0.3:25060');
});

it('reads position and quorum', function () {
    $this->scripts->respond('engines.mongodb.cluster.position', ['position' => 1731400000])->respond('engines.mongodb.cluster.quorum', ['total' => 3, 'online' => 2]);

    expect($this->node->driver()->cluster()->position())->toBe(1731400000)
        ->and($this->node->driver()->cluster()->quorum()->online)->toBe(2);
});

it('forces quorum only with the others confirmed down', function () {
    expect(fn () => $this->node->driver()->cluster()->forceQuorum(false))
        ->toThrow(EngineBusy::class, 'forcing the replica set while other members may be alive would roll back writes; confirm the others are down');

    $this->node->driver()->cluster()->forceQuorum(true);

    $this->scripts->assertRan('engines.mongodb.cluster.force-quorum', fn (string $script): bool => str_contains($script, 'a majority of voting members is healthy; the replica set elects a primary on its own'));
});
