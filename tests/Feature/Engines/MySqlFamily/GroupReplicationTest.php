<?php

declare(strict_types=1);

use App\Engines\Data\Member;
use App\Exceptions\Engines\EngineBusy;

beforeEach(function () {
    config(['databasify.php_engines' => ['mysql']]);
    $this->scripts = fakeScripts();
    $this->node = idleDatabase('mysql', 1, versionLine: '8.4')->nodes()->sole();
});

it('enables Group Replication as a task with the group name from the saved request', function () {
    $fixture = goFixture('configure-primary-9.7.json', 'mysql');
    respondToFileGet(fakeSsh(), 'state/node.json', json_encode($fixture['request']));

    $this->node->driver()->cluster()->enable('enable.1');

    $this->scripts->assertRan('mysql/cluster/enable', fn (string $script, array $env): bool => $env['GROUP_NAME'] === json_decode(file_get_contents(base_path('tests/Fixtures/mysql/group-name.json')), true)[$fixture['request']['databaseId']]
        && json_decode($env['RESERVED_DATABASES'], true) === ['mysql', 'sys', 'performance_schema', 'information_schema']
        && str_contains($script, "phase 'bootstrapping'")
        && str_contains($script, 'SET GLOBAL group_replication_bootstrap_group = ON')
        && str_contains($script, 'SET GLOBAL group_replication_clone_threshold = 1')
        && str_contains($script, 'a cluster needs a primary key on every table; add one to ')
        && str_contains($script, "SELECT asynchronous_connection_failover_add_managed('group', 'GroupReplication', ")
        && str_contains($script, 'CLONE INSTANCE FROM')
        && str_contains($script, 'MY_TIMEOUT=21600 my_try')
        && str_contains($script, 'SELECT STATE, ERROR_MESSAGE FROM performance_schema.clone_status'));
});

it('disables, fails over and changes the seeds with the Go statements', function () {
    $this->node->driver()->cluster()->disable();
    $this->node->driver()->cluster()->failover('10.0.0.2');
    $this->node->driver()->cluster()->membership([new Member('10.0.0.1', 1, true, 2, 4096, 80), new Member('10.0.0.4', 4, false, 2, 4096, 80), new Member('10.0.0.2', 2, true, 2, 4096, 80)]);
    $this->node->driver()->cluster()->forget('10.0.0.2');

    $this->scripts->assertRan('mysql/cluster/disable', fn (string $script): bool => str_contains($script, "RESET REPLICA ALL FOR CHANNEL 'group'") && str_contains($script, 'STOP GROUP_REPLICATION'))
        ->assertRan('mysql/cluster/failover', fn (string $script, array $env): bool => $env['TARGET'] === '10.0.0.2' && str_contains($script, 'is not a member of the group') && str_contains($script, 'group_replication_set_as_primary('))
        ->assertRan('mysql/cluster/membership', fn (string $script, array $env): bool => $env['SEEDS'] === '10.0.0.1:33061,10.0.0.2:33061')
        ->assertRan('mysql/members/forget', fn (string $script, array $env): bool => $env['SEED'] === '10.0.0.2:33061');
});

it('counts the transactions in the executed GTID set like the Go engine', function (string $executed) {
    $this->scripts->respond('mysql/cluster/position', ['executed' => $executed]);

    $position = $this->node->driver()->cluster()->position();

    expect($position)->toBe(json_decode(file_get_contents(base_path('tests/Fixtures/mysql/gtid.json')), true)[$executed]);
})->with(['', '3E11FA47-71CA-11E1-9E33-C80AA9429562:1-5', "a:1-5:7:9-10,\nb:3"]);

it('reports an unreadable position as -1', function () {
    $this->scripts->respond('mysql/cluster/position', ['executed' => null]);

    expect($this->node->driver()->cluster()->position())->toBe(-1);
});

it('reads the quorum from the group members and the saved voters', function () {
    $this->scripts->respond('mysql/cluster/quorum', ['total' => 3, 'online' => 2]);

    $quorum = $this->node->driver()->cluster()->quorum();

    expect($quorum->total)->toBe(3)->and($quorum->online)->toBe(2);
    $this->scripts->assertRan('mysql/cluster/quorum', fn (string $script): bool => str_contains($script, "COALESCE(SUM(MEMBER_STATE = 'ONLINE'), 0)") && str_contains($script, '[.members[] | select(.voting)] | length'));
});

it('forces quorum only once the other members are confirmed down', function () {
    expect(fn () => $this->node->driver()->cluster()->forceQuorum(false))
        ->toThrow(EngineBusy::class, 'forcing the group while other members may be alive would split it; confirm the others are down');

    $this->node->driver()->cluster()->forceQuorum(true);

    $this->scripts->assertRan('mysql/cluster/force-quorum', fn (string $script): bool => str_contains($script, 'group_replication_force_members') && str_contains($script, "CHANNEL_NAME = 'group_replication_applier'"));
});

it('rejoins as a task, following the group on a read-only node', function (bool $readOnly) {
    $this->node->driver()->cluster()->rejoin('rejoin.1', $readOnly);

    $this->scripts->assertRan('mysql/cluster/rejoin', fn (string $script, array $env): bool => $env['READ_ONLY'] === ($readOnly ? 'true' : 'false')
        && str_contains($script, "phase 'starting replication'")
        && str_contains($script, "phase 'waiting until the member is online'"));
})->with([true, false]);

it('names the latest replication errors when the member left the group with an error', function () {
    $this->node->driver()->cluster()->rejoin('rejoin.1', false);

    $this->scripts->assertRan('mysql/cluster/rejoin', fn (string $script): bool => str_contains($script, "SELECT DATA FROM performance_schema.error_log WHERE SUBSYSTEM = 'Repl' AND PRIO = 'Error' ORDER BY LOGGED DESC LIMIT 3")
        && str_contains($script, 'fail_task "this member left the group with an error: $(group_errors)"'));
});
