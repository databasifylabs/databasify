<?php

declare(strict_types=1);

use App\Actions\Databases\Failover\RecoverDatabase;
use App\Actions\Databases\Lifecycle\CreateDatabase;
use App\Actions\Databases\Nodes\RemoveDatabaseNode;
use App\DTOs\Databases\NewDatabase;
use App\Enums\Databases\NodeRole;
use App\Exceptions\Engines\EngineUnavailable;
use App\Jobs\Databases\RemoveNodeJob;
use App\Models\Database;
use App\Models\Server;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

it('never confirms the other nodes are down on the owner behalf and refuses an unreadable quorum', function () {
    $database = idleDatabase('mysql', 3);
    $ssh = fakeSsh();
    $database->nodes->each(fn ($node) => answerNodeStatus($ssh, $node->server, 'offline'));
    fakeEngine($ssh);
    answerRead('cluster.quorum', fn () => throw new EngineUnavailable('unavailable', 'not yet'));

    expect(fn () => resolve(RecoverDatabase::class)->handle($database))->toThrow(ValidationException::class, 'could not read');
});

it('checks the live role before removing a node recorded as a replica', function () {
    $database = idleDatabase('postgres', 3);
    markRoles($database, NodeRole::Replica, NodeRole::Replica, NodeRole::Replica);
    [$first, $second, $third] = $database->nodes()->orderBy('position')->get()->all();
    Queue::fake([RemoveNodeJob::class]);
    answerNodeStatus(answerNodeStatus(answerNodeStatus(fakeSsh(), $first->server, 'primary'), $second->server, 'replica'), $third->server, 'replica');

    resolve(RemoveDatabaseNode::class)->handle($first->fresh());

    Queue::assertPushed(RemoveNodeJob::class, fn (RemoveNodeJob $job): bool => $job->standby?->is($second) === true);
});

it('refuses to create a database on a server with no known size before anything runs', function () {
    $ssh = fakeEngine();
    $server = Server::factory()->ready()->create(['memory_mb' => null]);

    expect(fn () => inProjectDuring(fn (): Database => resolve(CreateDatabase::class)->handle(new NewDatabase('main', 'postgres', $server->id, []))))
        ->toThrow(ValidationException::class, 'has no known CPU, memory or disk size yet')
        ->and(Database::query()->count())->toBe(0)
        ->and($ssh->runs)->toBe([]);
});
