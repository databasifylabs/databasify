<?php

declare(strict_types=1);

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\NodeRole;
use App\Events\DatabaseAlertRaised;
use App\Exceptions\Ssh\SshConnectionFailed;
use App\Jobs\Databases\RefreshDatabaseStatusJob;
use App\Models\DatabaseNode;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

it('records the database server version each node reports', function () {
    $database = idleDatabase('valkey', 2);
    [$primary, $replica] = $database->nodes()->orderBy('position')->get()->map->server;
    answerNodeStatus(answerNodeStatus(fakeSsh(), $primary, 'primary', '8.1.4'), $replica, 'replica', '8.1.3');

    resolve(RefreshDatabaseStatus::class)->handle($database);

    expect($database->nodes()->orderBy('position')->pluck('engine_version')->all())->toBe(['8.1.4', '8.1.3']);
    $this->actingAs(User::factory()->create())
        ->get(projectUrl("/databases/{$database->id}"))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('database.data.nodes.0.engine_version', '8.1.4')->where('database.data.engine_version', $database->image_release));
});

it('keeps the last known version while a node is unreachable', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $database->nodes()->update(['engine_version' => '8.4.13']);
    fakeSsh()->respond('', 255);

    resolve(RefreshDatabaseStatus::class)->handle($database);

    expect($database->nodes()->sole()->engine_version)->toBe('8.4.13');
});

it('reports a node whose engine container is stopped as offline instead of unreachable', function () {
    $database = idleDatabase('postgres', 2);
    fakeSsh()->respond("databasify-postgres is not running on this server\n", 69)->respond('', 255);

    resolve(RefreshDatabaseStatus::class)->handle($database);

    expect($database->nodes()->orderBy('position')->pluck('role')->all())->toBe([NodeRole::Offline, NodeRole::Unknown]);
});

it('alerts when a ready database degrades and again when it heals', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $database = idleDatabase('valkey', 2);
    $database->update(['status' => DatabaseStatus::Ready]);
    [$primary, $replica] = $database->nodes()->orderBy('position')->get()->map->server;
    $unreachable = $replica->name;
    answerNodeStatus(answerNodeStatus(fakeSsh(), $primary, 'primary'), $replica, null);

    expect(resolve(RefreshDatabaseStatus::class)->handle($database))->toBe(DatabaseStatus::Degraded);
    Event::assertDispatched(DatabaseAlertRaised::class, fn (DatabaseAlertRaised $alert): bool => $alert->subject === 'main is degraded' && $alert->message === "Unreachable: {$unreachable}.");

    answerNodeStatus(answerNodeStatus(fakeSsh(), $primary, 'primary'), $replica, 'replica');

    expect(resolve(RefreshDatabaseStatus::class)->handle($database))->toBe(DatabaseStatus::Ready)
        ->and($database->fresh()->status)->toBe(DatabaseStatus::Ready);
    Event::assertDispatched(DatabaseAlertRaised::class, fn (DatabaseAlertRaised $alert): bool => $alert->subject === 'main is healthy again');
});

it('reports a database without a single primary as degraded', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $database = idleDatabase('valkey', 2);
    $database->update(['status' => DatabaseStatus::Ready]);
    $ssh = fakeSsh();
    $database->nodes->each(fn (DatabaseNode $node) => answerNodeStatus($ssh, $node->server, 'primary'));

    expect(resolve(RefreshDatabaseStatus::class)->handle($database))->toBe(DatabaseStatus::Degraded);
    Event::assertDispatched(DatabaseAlertRaised::class, fn (DatabaseAlertRaised $alert): bool => $alert->message === '2 nodes claim to be primary.');
});

it('raises an offline alert when no node answers any more', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $database->update(['status' => DatabaseStatus::Ready]);
    fakeSsh()->respond('', 255);

    expect(resolve(RefreshDatabaseStatus::class)->handle($database))->toBe(DatabaseStatus::Offline);
    Event::assertDispatched(DatabaseAlertRaised::class, fn (DatabaseAlertRaised $alert): bool => $alert->subject === 'main is offline');
});

it('stays quiet while a database is still being created', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    fakeSsh()->respond('', 255);

    expect(resolve(RefreshDatabaseStatus::class)->handle($database))->toBe(DatabaseStatus::Offline);
    Event::assertNotDispatched(DatabaseAlertRaised::class);
});

it('queues a status refresh from the database page', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    Queue::fake([RefreshDatabaseStatusJob::class]);

    $this->post(projectUrl("/databases/{$database->id}/refresh"))->assertRedirect();

    Queue::assertPushed(RefreshDatabaseStatusJob::class, fn (RefreshDatabaseStatusJob $job): bool => $job->database->is($database));
});

it('skips a queued refresh while the database is busy', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $database->startOperation('Upgrade');
    $ssh = fakeSsh();

    (new RefreshDatabaseStatusJob($database))->handle(resolve(RefreshDatabaseStatus::class));

    expect($ssh->runs)->toBeEmpty();
});

it('queues a status check for databases that are neither creating nor failed', function () {
    $ready = idleDatabase('valkey', 1, DatabaseMode::Single, 'ready');
    $ready->update(['status' => DatabaseStatus::Degraded]);
    idleDatabase('valkey', 1, DatabaseMode::Single, 'broken')->update(['status' => DatabaseStatus::Failed]);
    newDatabase('valkey', 1, DatabaseMode::Single, 'creating');
    Queue::fake([RefreshDatabaseStatusJob::class]);

    $this->artisan('databases:refresh-status')->expectsOutputToContain('Queued 1 status checks.')->assertSuccessful();

    Queue::assertPushed(RefreshDatabaseStatusJob::class, 1);
    Queue::assertPushed(RefreshDatabaseStatusJob::class, fn (RefreshDatabaseStatusJob $job): bool => $job->database->is($ready));
});

it('keeps the last known role through two missed reads and marks the node unreachable on the third', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $database->update(['status' => DatabaseStatus::Ready]);
    markRoles($database, NodeRole::Primary);
    fakeSsh()->failWith(new SshConnectionFailed('Connection closed due to timeout'));

    $missed = [resolve(RefreshDatabaseStatus::class)->handle($database), resolve(RefreshDatabaseStatus::class)->handle($database)];

    expect($missed)->toBe([DatabaseStatus::Ready, DatabaseStatus::Ready])
        ->and($database->nodes()->sole()->role)->toBe(NodeRole::Primary);
    Event::assertNotDispatched(DatabaseAlertRaised::class);

    expect(resolve(RefreshDatabaseStatus::class)->handle($database))->toBe(DatabaseStatus::Offline)
        ->and($database->nodes()->sole()->role)->toBe(NodeRole::Unknown);
    Event::assertDispatchedTimes(DatabaseAlertRaised::class, 1);
});

it('counts missed reads again from zero after a node answers', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $database->update(['status' => DatabaseStatus::Ready]);
    markRoles($database, NodeRole::Primary);
    $server = $database->nodes()->sole()->server;
    $missOnce = function () use ($database): void {
        fakeSsh()->failWith(new SshConnectionFailed('Connection closed due to timeout'));
        resolve(RefreshDatabaseStatus::class)->handle($database);
    };

    $missOnce();
    $missOnce();
    answerNodeStatus(fakeSsh(), $server, 'primary');
    resolve(RefreshDatabaseStatus::class)->handle($database);
    $missOnce();
    $missOnce();

    expect($database->fresh()->status)->toBe(DatabaseStatus::Ready)
        ->and($database->nodes()->sole()->role)->toBe(NodeRole::Primary);
    Event::assertNotDispatched(DatabaseAlertRaised::class);
});
