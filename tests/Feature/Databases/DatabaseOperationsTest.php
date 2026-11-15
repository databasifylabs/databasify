<?php

declare(strict_types=1);

use App\Contracts\Images\ImageRegistry;
use App\DTOs\Databases\EngineImage;
use App\DTOs\Databases\NodeStatus;
use App\DTOs\Databases\QuorumCount;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\NodeRole;
use App\Jobs\Databases\CreateDatabaseJob;
use App\Jobs\Databases\FailoverDatabaseJob;
use App\Jobs\Databases\RebootDatabaseJob;
use App\Jobs\Databases\RecoverClusterJob;
use App\Jobs\Databases\RejoinMembersJob;
use App\Jobs\Databases\RetuneDatabaseJob;
use App\Jobs\Databases\UpgradeDatabaseJob;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\EngineRecording;
use Tests\Support\FakeImageRegistry;
use Tests\Support\RecordingDriver;

/**
 * @param  list<NodeRole>  $roles
 * @return Collection<int, DatabaseNode>
 */
function clusterAnswering(Database $database, array $roles): Collection
{
    $nodes = collect($roles)->map(fn (NodeRole $role, int $index) => DatabaseNode::factory()->for($database)->create([
        'position' => $index + 1,
        'role' => $index === 0 ? NodeRole::Primary : NodeRole::Replica,
    ]));
    RecordingDriver::fake()->on('nodes.status', fn (DatabaseNode $node) => new NodeStatus($roles[$nodes->search(fn (DatabaseNode $candidate) => $candidate->is($node))], '18.1'));

    return $nodes;
}

function postOperation(Database $database, string $route, array $data = []): TestResponse
{
    return test()->actingAs(User::factory()->create())->post(route($route, [$database->project_id, $database]), $data);
}

test('a database with a standby can fail over', function () {
    Queue::fake([FailoverDatabaseJob::class]);
    $database = Database::factory()->highlyAvailable()->create();
    DatabaseNode::factory()->for($database)->create();
    DatabaseNode::factory()->for($database)->replica()->create();

    postOperation($database, 'databases.failover')->assertSessionHasNoErrors();

    expect($database->refresh()->status)->toBe(DatabaseStatus::Recovering);
    Queue::assertPushed(FailoverDatabaseJob::class);
});

test('failover needs a standby', function () {
    Queue::fake([FailoverDatabaseJob::class]);
    $database = Database::factory()->create();
    DatabaseNode::factory()->for($database)->create();

    postOperation($database, 'databases.failover')->assertSessionHasErrors(['database' => 'Failover needs at least one standby node.']);

    expect($database->refresh()->status)->toBe(DatabaseStatus::Ready);
    Queue::assertNotPushed(FailoverDatabaseJob::class);
});

test('a busy database refuses new operations', function (string $route, string $job) {
    Queue::fake([$job]);
    $database = Database::factory()->highlyAvailable()->status(DatabaseStatus::Upgrading)->create(['name' => 'orders']);
    DatabaseNode::factory()->for($database)->create();
    DatabaseNode::factory()->for($database)->replica()->create();

    postOperation($database, $route)->assertSessionHasErrors(['database' => 'orders is busy upgrading, try again when it finishes.']);

    expect($database->refresh()->status)->toBe(DatabaseStatus::Upgrading);
    Queue::assertNotPushed($job);
})->with([
    'failover' => ['databases.failover', FailoverDatabaseJob::class],
    'retune' => ['databases.retune', RetuneDatabaseJob::class],
    'reboot' => ['databases.reboot', RebootDatabaseJob::class],
    'recover' => ['databases.recover', RecoverClusterJob::class],
]);

test('refreshing a database records what each node reports', function () {
    $database = Database::factory()->highlyAvailable()->create();
    [$primary, $standby] = clusterAnswering($database, [NodeRole::Replica, NodeRole::Primary]);

    postOperation($database, 'databases.refresh')->assertSessionHasNoErrors();

    expect($primary->refresh()->role)->toBe(NodeRole::Replica)
        ->and($standby->refresh()->role)->toBe(NodeRole::Primary)
        ->and($standby->engine_version)->toBe('18.1');
});

test('checking for an upgrade records the newer supported image', function () {
    app()->instance(ImageRegistry::class, new FakeImageRegistry(['18.3' => '0.1.0', '18.2' => '0.2.2', '18.1' => '0.2.2']));
    $database = Database::factory()->create();

    postOperation($database, 'databases.upgrade-check')->assertSessionHasNoErrors();

    expect($database->refresh()->available_image?->release)->toBe('18.2');
});

test('a database upgrades to its available image', function () {
    Queue::fake([UpgradeDatabaseJob::class]);
    $newer = new EngineImage('ghcr.io/databasifylabs/engine-postgres', 'sha256:'.str_repeat('b', 64), '18.2', '0.2.2');
    $database = Database::factory()->create(['available_image' => $newer]);

    postOperation($database, 'databases.upgrade')->assertSessionHasNoErrors();

    expect($database->refresh())
        ->status->toBe(DatabaseStatus::Upgrading)
        ->image->release->toBe('18.2');
    Queue::assertPushed(UpgradeDatabaseJob::class);
});

test('a database already on the newest release is not upgraded', function () {
    Queue::fake([UpgradeDatabaseJob::class]);
    $database = Database::factory()->create(['name' => 'orders']);

    postOperation($database, 'databases.upgrade')->assertSessionHasErrors(['version' => 'orders already runs the newest PostgreSQL 18 release.']);

    Queue::assertNotPushed(UpgradeDatabaseJob::class);
});

test('a database is retuned', function () {
    Queue::fake([RetuneDatabaseJob::class]);
    $database = Database::factory()->create();

    postOperation($database, 'databases.retune')->assertSessionHasNoErrors();

    expect($database->refresh()->status)->toBe(DatabaseStatus::Updating);
    Queue::assertPushed(RetuneDatabaseJob::class);
});

test('a healthy database is rebooted', function () {
    Queue::fake([RebootDatabaseJob::class]);
    $database = Database::factory()->highlyAvailable()->create();
    clusterAnswering($database, [NodeRole::Primary, NodeRole::Replica]);

    postOperation($database, 'databases.reboot')->assertSessionHasNoErrors();

    expect($database->refresh()->status)->toBe(DatabaseStatus::Rebooting);
    Queue::assertPushed(RebootDatabaseJob::class);
});

test('a degraded database is not rebooted', function () {
    Queue::fake([RebootDatabaseJob::class]);
    $database = Database::factory()->highlyAvailable()->create(['name' => 'orders']);
    clusterAnswering($database, [NodeRole::Primary, NodeRole::Offline]);

    postOperation($database, 'databases.reboot')->assertSessionHasErrors(['database' => 'orders is degraded, bring every node back before rebooting its servers one by one.']);

    expect($database->refresh()->status)->toBe(DatabaseStatus::Ready);
    Queue::assertNotPushed(RebootDatabaseJob::class);
});

test('recovery is refused when no member answers', function () {
    Queue::fake([RecoverClusterJob::class, RejoinMembersJob::class]);
    $database = Database::factory()->highlyAvailable()->create(['name' => 'orders']);
    clusterAnswering($database, [NodeRole::Unknown, NodeRole::Unknown]);

    postOperation($database, 'databases.recover')->assertSessionHasErrors(['database' => 'orders has no reachable member to recover from.']);

    Queue::assertNothingPushed();
});

test('recovering from a minority needs confirmation that the others are down', function () {
    Queue::fake([RecoverClusterJob::class, RejoinMembersJob::class]);
    $database = Database::factory()->highlyAvailable()->create();
    clusterAnswering($database, [NodeRole::Replica, NodeRole::Unknown, NodeRole::Unknown]);

    postOperation($database, 'databases.recover')->assertSessionHasErrors(['database' => 'Only 1 of 3 members answer. If the others are really down and not just cut off from Databasify, recover again and confirm they are down.']);

    Queue::assertNothingPushed();
});

test('a cluster without quorum is recovered from the members that answer', function () {
    Queue::fake([RecoverClusterJob::class, RejoinMembersJob::class]);
    $database = Database::factory()->highlyAvailable()->create();
    [, $survivor] = clusterAnswering($database, [NodeRole::Unknown, NodeRole::Replica, NodeRole::Unknown]);
    app(EngineRecording::class)->on('cluster.quorum', new QuorumCount(3, 1));

    postOperation($database, 'databases.recover', ['others_down' => true])->assertSessionHasNoErrors();

    expect($database->refresh()->status)->toBe(DatabaseStatus::Recovering);
    Queue::assertPushed(RecoverClusterJob::class, fn (RecoverClusterJob $job) => $job->nodeIds === [$survivor->id] && $job->othersDown);
});

test('members that dropped out of a healthy cluster are rejoined', function () {
    Queue::fake([RecoverClusterJob::class, RejoinMembersJob::class]);
    $database = Database::factory()->highlyAvailable()->create();
    [, , $outsider] = clusterAnswering($database, [NodeRole::Primary, NodeRole::Replica, NodeRole::Offline]);
    app(EngineRecording::class)->on('cluster.quorum', new QuorumCount(3, 2));

    postOperation($database, 'databases.recover')->assertSessionHasNoErrors();

    Queue::assertPushed(RejoinMembersJob::class, fn (RejoinMembersJob $job) => $job->nodeIds === [$outsider->id]);
    Queue::assertNotPushed(RecoverClusterJob::class);
});

test('a cluster that still has a majority is pointed to failover', function () {
    Queue::fake([RecoverClusterJob::class, RejoinMembersJob::class]);
    $database = Database::factory()->highlyAvailable()->create(['name' => 'orders']);
    clusterAnswering($database, [NodeRole::Primary, NodeRole::Replica, NodeRole::Replica]);
    app(EngineRecording::class)->on('cluster.quorum', new QuorumCount(3, 3));

    postOperation($database, 'databases.recover')->assertSessionHasErrors(['database' => 'orders still has a majority, use failover instead.']);

    expect($database->refresh()->status)->toBe(DatabaseStatus::Ready);
    Queue::assertNothingPushed();
});

test('a failed setup can be retried', function () {
    Queue::fake([CreateDatabaseJob::class]);
    $database = Database::factory()->status(DatabaseStatus::Failed)->create();

    postOperation($database, 'databases.retry-setup')->assertSessionHasNoErrors();

    expect($database->refresh()->status)->toBe(DatabaseStatus::Pending);
    Queue::assertPushed(CreateDatabaseJob::class);
});

test('a finished setup is not retried', function () {
    Queue::fake([CreateDatabaseJob::class]);
    $database = Database::factory()->create(['name' => 'orders']);

    postOperation($database, 'databases.retry-setup')->assertSessionHasErrors(['database' => 'orders finished its setup, there is nothing to retry.']);

    Queue::assertNotPushed(CreateDatabaseJob::class);
});
