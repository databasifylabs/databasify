<?php

declare(strict_types=1);

use App\Actions\Databases\Failover\RecoverDatabase;
use App\Actions\Databases\Flows\StartFlow;
use App\Actions\Databases\Lifecycle\CreateDatabase;
use App\Actions\Databases\Nodes\RemoveDatabaseNode;
use App\DTOs\Databases\NewDatabase;
use App\Engines\Flows\FlowContext;
use App\Engines\Flows\FlowSteps;
use App\Engines\Flows\Step;
use App\Engines\Requests\NodeReady;
use App\Enums\Databases\FlowState;
use App\Enums\Databases\NodeRole;
use App\Exceptions\Ssh\SshConnectionFailed;
use App\Jobs\Databases\AdvanceDatabaseFlow;
use App\Jobs\Databases\CleanUpDatabaseFlow;
use App\Models\Database;
use App\Models\Server;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Support\ScriptedFlow;

it('keeps a deterministic key when starting a long step breaks, so the next tick finds the operation', function () {
    $database = idleDatabase('postgres', 1);
    $node = $database->seed();
    fakeSsh()->failWith(SshConnectionFailed::onServer($node->server, 'connection reset'));
    $flow = ScriptedFlow::bind([Step::catchUp('copy', resolve(FlowSteps::class)->long($node, fn (string $key): NodeReady => new NodeReady($key, false), 'waiting'))]);

    $record = resolve(StartFlow::class)->handle($database, $flow, new FlowContext([$node->id]))->fresh();

    expect($record)->state->toBe(FlowState::Running)->operation_key->toBe("{$record->id}.0.0")
        ->and($flow->cleanups)->toBe(0);

    fakeEngine();
    runJob(new AdvanceDatabaseFlow($record));

    expect($record->fresh()->state)->toBe(FlowState::Succeeded);
});

it('starts a lost operation again under the next attempt key', function () {
    $database = idleDatabase('postgres', 1);
    $node = $database->seed();
    fakeEngine();
    $flow = ScriptedFlow::bind([Step::prepare('wait', resolve(FlowSteps::class)->long($node, fn (string $key): NodeReady => new NodeReady($key, false), 'waiting'))]);
    Queue::fake([AdvanceDatabaseFlow::class]);
    $record = resolve(StartFlow::class)->handle($database, $flow, new FlowContext([$node->id]));
    runJob(new AdvanceDatabaseFlow($record));

    fakeEngine(fakeSsh()->respondToEngine('operation status', ['error' => ['code' => 'not_found', 'message' => 'gone', 'details' => (object) []]], exit: 1));
    runJob(new AdvanceDatabaseFlow($record->fresh()));

    expect($record->fresh()->operation_key)->toBe("{$record->id}.0.1");
});

it('runs the cleanup of a failed flow in its own long job', function () {
    $database = idleDatabase('postgres', 1);
    Queue::fake([CleanUpDatabaseFlow::class]);
    $flow = ScriptedFlow::bind([ScriptedFlow::quick(fn () => throw new RuntimeException('boom'))]);

    $record = resolve(StartFlow::class)->handle($database, $flow, new FlowContext([]));

    expect($record->fresh()->state)->toBe(FlowState::Failed)
        ->and($flow->cleanups)->toBe(0)
        ->and($database->fresh()->operation)->not->toBeNull();
    Queue::assertPushed(CleanUpDatabaseFlow::class, fn (CleanUpDatabaseFlow $job): bool => $job->flow->is($record));
    expect((new ReflectionClass(CleanUpDatabaseFlow::class))->getAttributes(Timeout::class)[0]->getArguments()[0])->toBeGreaterThanOrEqual(3600);
});

it('gives up on a finish step that keeps failing instead of holding the lock forever', function () {
    $database = idleDatabase('postgres', 1);
    $flow = ScriptedFlow::bind([Step::finish('never works', fn () => throw new RuntimeException('peer gone'))]);
    $record = resolve(StartFlow::class)->handle($database, $flow, new FlowContext([]));

    foreach (range(1, 40) as $ignored) {
        runJob(new AdvanceDatabaseFlow($record->fresh()));
    }

    expect($record->fresh())->state->toBe(FlowState::Failed)->error->toContain('peer gone')
        ->and($database->fresh()->operation)->toBeNull();
});

it('never confirms the other nodes are down on the owner behalf and refuses an unreadable quorum', function () {
    $database = idleDatabase('mysql', 3);
    $ssh = fakeSsh();
    $database->nodes->each(fn ($node) => answerNodeStatus($ssh, $node->server, 'offline'));
    fakeEngine($ssh)->respondToEngine('cluster quorum', ['error' => ['code' => 'unavailable', 'message' => 'not yet', 'details' => (object) []]], exit: 1);

    expect(fn () => resolve(RecoverDatabase::class)->handle($database))->toThrow(ValidationException::class, 'could not read');
});

it('checks the live role before removing a node recorded as a replica', function () {
    $database = idleDatabase('postgres', 3);
    markRoles($database, NodeRole::Replica, NodeRole::Replica, NodeRole::Replica);
    [$first, $second, $third] = $database->nodes()->orderBy('position')->get()->all();
    Queue::fake([AdvanceDatabaseFlow::class]);
    answerNodeStatus(answerNodeStatus(answerNodeStatus(fakeSsh(), $first->server, 'primary'), $second->server, 'replica'), $third->server, 'replica');

    resolve(RemoveDatabaseNode::class)->handle($first->fresh());

    expect($database->flows()->running()->sole()->context->failoverTo)->toBe($second->id);
});

it('refuses to create a database on a server with no known size before anything runs', function () {
    $ssh = fakeEngine();
    $server = Server::factory()->ready()->create(['memory_mb' => null]);

    expect(fn () => inProjectDuring(fn (): Database => resolve(CreateDatabase::class)->handle(new NewDatabase('main', 'postgres', $server->id, []))))
        ->toThrow(ValidationException::class, 'has no known CPU, memory or disk size yet')
        ->and(Database::query()->count())->toBe(0)
        ->and($ssh->runs)->toBe([]);
});
