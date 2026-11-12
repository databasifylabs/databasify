<?php

declare(strict_types=1);

use App\Engines\Data\Member;
use App\Engines\EngineClient;
use App\Engines\Requests\ClusterDisable;
use App\Engines\Requests\ClusterEnable;
use App\Engines\Requests\ClusterFailover;
use App\Engines\Requests\ClusterForceQuorum;
use App\Engines\Requests\ClusterMembership;
use App\Engines\Requests\ClusterPosition;
use App\Engines\Requests\ClusterQuorum;
use App\Engines\Requests\ClusterRejoin;
use App\Engines\Requests\MemberForget;
use App\Exceptions\Engines\EngineBusy;

beforeEach(function () {
    config(['databasify.php_engines' => ['mongodb']]);
    $this->scripts = fakeScripts();
    $this->node = idleDatabase('mongodb', 1)->nodes()->sole();
    respondToFileGet(fakeSsh(), 'state/node.json', json_encode(goFixture('configure-archived.json', 'mongodb')['request']));
});

it('joins the replica set as a task with the saved members', function () {
    resolve(EngineClient::class)->send($this->node, new ClusterEnable('enable.1'));
    resolve(EngineClient::class)->send($this->node, new ClusterRejoin('rejoin.1', false));

    $this->scripts->assertRan('mongodb/cluster/enable', fn (string $script, array $env): bool => $env['HOSTS'] === '10.0.0.1:25060,10.0.0.2:25060' && $env['SELF'] === '10.0.0.2:25060' && $env['SEED'] === 'true'
        && str_contains($script, "phase 'syncing'") && str_contains($script, "phase 'voting'") && str_contains($script, 'withMember(configOf(primary.admin), env.SELF)')
        && str_contains($script, 'this member is too stale to catch up and no primary is reachable to copy from; its data stays untouched'))
        ->assertRan('mongodb/cluster/rejoin', fn (string $script): bool => str_contains($script, "phase 'starting MongoDB'"));
});

it('runs the replica set commands with the Go reconfig rules', function () {
    $client = resolve(EngineClient::class);
    $client->send($this->node, new ClusterDisable);
    $client->send($this->node, new ClusterFailover('10.0.0.1'));
    $client->send($this->node, new ClusterMembership([new Member('10.0.0.1', 1, true, 2, 4096, 80), new Member('10.0.0.2', 2, false, 2, 4096, 80)]));
    $client->send($this->node, new MemberForget('10.0.0.3'));

    $this->scripts->assertRan('mongodb/cluster/disable', fn (string $script): bool => str_contains($script, 'votingAlone(self)') && str_contains($script, 'reconfig(local(), alone, true)'))
        ->assertRan('mongodb/cluster/failover', fn (string $script, array $env): bool => $env['TARGET_HOST'] === '10.0.0.1:25060' && str_contains($script, 'is not a member of the replica set') && str_contains($script, 'replSetStepDown: 60'))
        ->assertRan('mongodb/cluster/membership', fn (string $script, array $env): bool => $env['WANTED'] === '10.0.0.1:25060,10.0.0.2:25060' && $env['NON_VOTING'] === '10.0.0.2:25060')
        ->assertRan('mongodb/members/forget', fn (string $script, array $env): bool => $env['GONE'] === '10.0.0.3:25060');
});

it('reads position and quorum', function () {
    $this->scripts->respond('mongodb/cluster/position', ['position' => 1731400000])->respond('mongodb/cluster/quorum', ['total' => 3, 'online' => 2]);

    expect(resolve(EngineClient::class)->send($this->node, new ClusterPosition)->position)->toBe(1731400000)
        ->and(resolve(EngineClient::class)->send($this->node, new ClusterQuorum)->online)->toBe(2);
});

it('forces quorum only with the others confirmed down', function () {
    expect(fn () => resolve(EngineClient::class)->send($this->node, new ClusterForceQuorum(false)))
        ->toThrow(EngineBusy::class, 'forcing the replica set while other members may be alive would roll back writes; confirm the others are down');

    resolve(EngineClient::class)->send($this->node, new ClusterForceQuorum(true));

    $this->scripts->assertRan('mongodb/cluster/force-quorum', fn (string $script): bool => str_contains($script, 'a majority of voting members is healthy; the replica set elects a primary on its own'));
});
