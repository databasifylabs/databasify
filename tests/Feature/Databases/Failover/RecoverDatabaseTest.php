<?php

declare(strict_types=1);

use App\Actions\Databases\Failover\PromoteSurvivor;
use App\Actions\Databases\Failover\RecoverDatabase;
use App\Enums\Databases\NodeRole;
use App\Jobs\Databases\RecoverDatabaseJob;
use App\Models\DatabaseNode;
use App\Models\ServerLog;
use App\Models\User;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

it('recovers a mysql database that lost its primary', function () {
    $database = idleDatabase('mysql', 2);
    Queue::fake([RecoverDatabaseJob::class]);
    fakeSsh()->respond('', 1)->respond("SECONDARY ONLINE\n")->respond("total=2\nonline=1\n");

    resolve(RecoverDatabase::class)->handle($database, othersAreDown: true);

    Queue::assertPushed(RecoverDatabaseJob::class);
});

it('refuses to force quorum while a member still sees a majority', function () {
    $database = idleDatabase('mysql', 3);
    Queue::fake([RecoverDatabaseJob::class]);
    fakeSsh()->respond("PRIMARY ONLINE\n")->respond("SECONDARY ONLINE\n")->respond('', 1)->respond("total=3\nonline=2\n");

    expect(fn () => resolve(RecoverDatabase::class)->handle($database))->toThrow(ValidationException::class, 'use failover instead');
});

it('recovers when the surviving primary has lost its majority', function () {
    $database = idleDatabase('mysql', 3);
    Queue::fake([RecoverDatabaseJob::class]);
    fakeSsh()->respond("PRIMARY ONLINE\n")->respond('', 1)->respond('', 1)->respond("total=3\nonline=1\n");

    resolve(RecoverDatabase::class)->handle($database, othersAreDown: true);

    Queue::assertPushed(RecoverDatabaseJob::class, fn (RecoverDatabaseJob $job): bool => $job->othersAreDown);
});

it('refuses to force quorum from a minority until the user confirms the others are down', function () {
    $database = idleDatabase('mysql', 3);
    Queue::fake([RecoverDatabaseJob::class]);
    fakeSsh()->respond("PRIMARY ONLINE\n")->respond('', 1)->respond('', 1);

    expect(fn () => resolve(RecoverDatabase::class)->handle($database))->toThrow(ValidationException::class, 'Only 1 of 3 members answer.');
    Queue::assertNothingPushed();
});

it('forces quorum on the surviving member', function () {
    $database = idleDatabase('mysql', 2);
    $survivor = $database->nodes()->get()[1];
    $database->startOperation('Recover');
    $ssh = fakeSsh()->respond('', 1)->respond("SECONDARY ONLINE\n")->respond()->respond('', 1)->respond("PRIMARY ONLINE\n");

    runJob(new RecoverDatabaseJob($database));

    expect($ssh->runs[2]['server'])->toBe($survivor->server->name)
        ->and($ssh->scriptAt(2))->toContain("force_quorum_using_partition_of('{$survivor->privateIp()}:25060')")
        ->and($database->fresh()->operation)->toBeNull();
});

it('records the role of the recovered primary before the operation finishes', function () {
    $database = idleDatabase('mysql', 2);
    $survivor = $database->nodes()->get()[1];
    $database->startOperation('Recover');
    fakeSsh()->respond('', 1)->respond("SECONDARY ONLINE\n")->respond()->respond('', 1)->respond("PRIMARY ONLINE\n");
    $operationWhenRecorded = null;
    DatabaseNode::updated(function (DatabaseNode $node) use ($survivor, $database, &$operationWhenRecorded): void {
        if ($node->is($survivor) && $node->role === NodeRole::Primary) {
            $operationWhenRecorded = $database->fresh()->operation;
        }
    });

    runJob(new RecoverDatabaseJob($database));

    expect($operationWhenRecorded)->toBe('Recover')
        ->and($database->fresh()->operation)->toBeNull();
});

it('recovers from a complete outage where every member answers but none serves', function () {
    $database = idleDatabase('mysql', 3);
    Queue::fake([RecoverDatabaseJob::class]);
    fakeSsh()->respond("UNKNOWN OFFLINE\n")->respond("UNKNOWN OFFLINE\n")->respond("UNKNOWN OFFLINE\n")->respond("total=0\nonline=0\n");

    resolve(RecoverDatabase::class)->handle($database);

    expect($database->nodes()->pluck('role')->all())->toBe([NodeRole::Offline, NodeRole::Offline, NodeRole::Offline]);
    Queue::assertPushed(RecoverDatabaseJob::class);
});

it('starts every stopped member before a complete-outage reboot so the newest data seeds the cluster', function () {
    $database = idleDatabase('mysql', 3);
    $servers = $database->nodes()->with('server')->get()->map(fn ($node) => $node->server->name)->all();
    $database->startOperation('Recover');
    $ssh = fakeSsh()->respond("UNKNOWN OFFLINE\n")->respond("UNKNOWN OFFLINE\n")->respond("UNKNOWN OFFLINE\n");

    resolve(PromoteSurvivor::class)->handle($database);

    expect(collect($ssh->runs)->slice(3, 4)->pluck('server')->all())->toBe([...$servers, $servers[0]])
        ->and($ssh->scriptAt(3))->toContain('databasify ctl start "$process"')->toContain('docker start databasify-mysql')
        ->and($ssh->scriptAt(6))->toContain('reboot_cluster_from_complete_outage')
        ->and($database->serverLogs()->pluck('name'))->toContain("{$servers[2]}: starting the engine so its data is compared");
});

it('bootstraps galera from the member with the highest committed position and then starts the rest', function () {
    $database = idleDatabase('mariadb', 3);
    $servers = $database->nodes()->with('server')->get()->map(fn ($node) => $node->server->name)->all();
    $database->startOperation('Recover');
    $ssh = fakeSsh()->respond("UNKNOWN OFFLINE\n")->respond("UNKNOWN OFFLINE\n")->respond("UNKNOWN OFFLINE\n")
        ->respond("position=41\n")->respond("position=57\n")->respond("position=-1\n");

    runJob(new RecoverDatabaseJob($database));

    expect($ssh->runs[6]['server'])->toBe($servers[1])
        ->and($ssh->scriptAt(6))->toContain('safe_to_bootstrap: 1')->toContain('galera_new_cluster')
        ->and(collect($ssh->runs)->slice(7, 2)->pluck('server')->all())->toBe([$servers[0], $servers[2]])
        ->and($ssh->scriptAt(7))->toContain('databasify ctl start "$process"')
        ->and($database->serverLogs()->pluck('name'))->toContain("forcing quorum on {$servers[1]}, the unreachable members rejoin when they come back")
        ->and($database->fresh()->operation)->toBeNull();
});

it('refuses to recover when no member answers or another operation runs', function () {
    $database = idleDatabase('mysql', 2);
    Queue::fake([RecoverDatabaseJob::class]);
    fakeSsh()->respond('', 255)->respond('', 255);

    expect(fn () => resolve(RecoverDatabase::class)->handle($database))->toThrow(ValidationException::class, 'has no reachable member to recover from');

    $database->startOperation('Upgrade');

    expect(fn () => resolve(RecoverDatabase::class)->handle($database))->toThrow(ValidationException::class, 'busy with Upgrade');
    Queue::assertNothingPushed();
});

it('fails the recovery when no member is left to promote', function () {
    $database = idleDatabase('mysql', 2);
    $database->startOperation('Recover');
    fakeSsh()->respond('', 255)->respond('', 255);

    expect(fn () => runJob(new RecoverDatabaseJob($database)))->toThrow(RuntimeException::class, 'No reachable member left to recover from.')
        ->and($database->fresh()->operation)->toBeNull();
});

it('recovers from the database page', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('mysql', 2);
    $survivor = $database->nodes()->get()[1];
    $ssh = fakeSsh()
        ->respond('', 1)->respond("SECONDARY ONLINE\n")->respond("total=2\nonline=1\n")
        ->respond('', 1)->respond("SECONDARY ONLINE\n")->respond()
        ->respond('', 1)->respond("PRIMARY ONLINE\n");

    $this->post(projectUrl("/databases/{$database->id}/recover"), ['others_down' => true])->assertRedirect();

    expect($ssh->scriptAt(5))->toContain('force_quorum_using_partition_of')
        ->and($survivor->fresh()->role)->toBe(NodeRole::Primary)
        ->and($database->fresh()->operation)->toBeNull();
});

it('refuses to force quorum on a sentinel database whose primary still serves', function () {
    $database = idleDatabase('valkey', 3);
    Queue::fake([RecoverDatabaseJob::class]);
    fakeSsh()->respond("role:master\n")->respond("role:slave\nmaster_link_status:up\nmaster_sync_in_progress:0\n")->respond("role:slave\nmaster_link_status:up\nmaster_sync_in_progress:0\n")->respond("total=3\nonline=3\n");

    expect(fn () => resolve(RecoverDatabase::class)->handle($database))->toThrow(ValidationException::class, 'use failover instead');
    Queue::assertNothingPushed();
});

it('hands the confirmation that the others are down to the survivor so postgres forces a single-member etcd', function () {
    $database = idleDatabase('postgres', 3);
    $survivor = $database->nodes()->get()[1];
    $database->startOperation('Recover');
    $ssh = fakeSsh()->respond('', 255)->respond("role:replica state:running\n")->respond('', 255)
        ->respond("position=4096\n")->respond();

    runJob(new RecoverDatabaseJob($database, othersAreDown: true));

    expect($ssh->runs[4]['server'])->toBe($survivor->server->name)
        ->and($ssh->scriptAt(4))->toContain("echo 'ETCD_FORCE_NEW_CLUSTER=true' | put_file")->not->toContain('etcd has no majority')
        ->and($database->fresh()->operation)->toBeNull();
});

it('keeps refusing to force a single-member etcd without the confirmation', function () {
    $database = idleDatabase('postgres', 3);
    $database->startOperation('Recover');
    $ssh = fakeSsh()->respond("role:unknown state:stopped\n")->respond("role:unknown state:stopped\n")->respond("role:unknown state:stopped\n");

    resolve(PromoteSurvivor::class)->handle($database);

    expect(collect($ssh->runs)->pluck('script')->filter(fn (string $script): bool => str_contains($script, 'etcd_up()'))->first())
        ->toContain('etcd has no majority')->not->toContain('ETCD_FORCE_NEW_CLUSTER');
});

it('restarts a fully stopped sentinel database from the member with the highest replication offset', function () {
    $database = idleDatabase('valkey', 3);
    $nodes = $database->nodes()->with('server')->get();
    $servers = $nodes->map(fn ($node) => $node->server->name)->all();
    $database->startOperation('Recover');
    $ssh = fakeSsh()->respond("role:unknown\n")->respond("role:unknown\n")->respond("role:unknown\n")
        ->respond()->respond()->respond()
        ->respond("position=900\n")->respond("position=1200\n")->respond("position=-1\n");

    runJob(new RecoverDatabaseJob($database));

    expect($ssh->scriptAt(3))->toContain('docker start databasify-valkey')->toContain('databasify ctl kill -s KILL databasify-valkey')
        ->and($ssh->scriptAt(6))->toContain('AUX FIELD repl-offset')
        ->and($ssh->runs[9]['server'])->toBe($servers[1])
        ->and($ssh->scriptAt(9))->toContain("sentinel monitor main {$nodes[1]->privateIp()} ")->toContain('vcli REPLICAOF NO ONE')
        ->and(collect($ssh->runs)->slice(10, 2)->pluck('server')->all())->toBe([$servers[0], $servers[2]])
        ->and($ssh->scriptAt(10))->toContain('rejoined as a replica of')
        ->and($database->fresh()->operation)->toBeNull();
});

it('reads the position of every member in one parallel pass and seeds from the first of the newest', function () {
    $database = idleDatabase('mariadb', 3);
    $servers = $database->nodes()->with('server')->get()->map(fn ($node) => $node->server->name)->all();
    $database->startOperation('Recover');
    $ssh = fakeSsh()->respond("UNKNOWN OFFLINE\n")->respond("UNKNOWN OFFLINE\n")->respond("UNKNOWN OFFLINE\n")
        ->respond("position=57\n")->respond("position=57\n")->respond("position=12\n");
    $passes = [];
    Concurrency::shouldReceive('run')->andReturnUsing(function (array $tasks) use (&$passes): array {
        $before = ServerLog::query()->pluck('id');
        $results = array_map(fn (Closure $task): mixed => $task(), $tasks);
        $passes[] = ServerLog::query()->whereNotIn('id', $before)->orderBy('id')->pluck('name')->all();

        return $results;
    });

    runJob(new RecoverDatabaseJob($database));

    expect($passes)->toContain(array_map(fn (string $server): string => "{$server}: reading its last committed position", $servers))
        ->and($ssh->runs[6]['server'])->toBe($servers[0])
        ->and($ssh->scriptAt(6))->toContain('galera_new_cluster')
        ->and($database->fresh()->operation)->toBeNull();
});

it('does not name a member as the seed when the engine picks the most up-to-date member itself', function () {
    $database = idleDatabase('mysql', 3);
    $servers = $database->nodes()->with('server')->get()->map(fn ($node) => $node->server->name)->all();
    $database->startOperation('Recover');
    fakeSsh()->respond("UNKNOWN OFFLINE\n")->respond("UNKNOWN OFFLINE\n")->respond("UNKNOWN OFFLINE\n");

    resolve(PromoteSurvivor::class)->handle($database);

    expect($database->serverLogs()->pluck('name'))
        ->toContain("forcing quorum from {$servers[0]}, the most up-to-date member seeds the cluster and the unreachable members rejoin when they come back")
        ->not->toContain("forcing quorum on {$servers[0]}, the unreachable members rejoin when they come back");
});
