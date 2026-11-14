<?php

declare(strict_types=1);

use App\Actions\Databases\Nodes\RemoveDatabaseNode;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\NodeKind;
use App\Enums\Databases\NodeRole;
use App\Jobs\Databases\RemoveNodeJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

it('refuses to remove the last member', function () {
    $database = idleDatabase('valkey', 2);
    [$primary, $standby] = $database->nodes()->get()->all();
    $primary->update(['role' => NodeRole::Primary]);
    $standby->update(['role' => NodeRole::Replica, 'kind' => NodeKind::ReadOnly]);

    expect(fn () => resolve(RemoveDatabaseNode::class)->handle($primary->fresh()))->toThrow(ValidationException::class, 'only member');
});

it('plans a failover to a standby before removing the primary', function () {
    $database = idleDatabase('valkey', 3);
    Queue::fake([RemoveNodeJob::class]);
    markRoles($database, NodeRole::Primary, NodeRole::Replica, NodeRole::Replica);
    [$primary, $standby] = $database->nodes()->orderBy('position')->get()->all();
    $ssh = fakeSsh();
    $database->nodes()->orderBy('position')->get()->each(fn ($node, int $index) => answerNodeStatus($ssh, $node->server, $index === 0 ? 'primary' : 'replica'));

    resolve(RemoveDatabaseNode::class)->handle($primary->fresh());

    Queue::assertPushed(RemoveNodeJob::class, fn (RemoveNodeJob $job): bool => $job->node->is($primary) && $job->standby?->is($standby) === true);
});

it('lets standby and read-only nodes be removed', function () {
    $database = idleDatabase('valkey', 3);
    Queue::fake([RemoveNodeJob::class]);
    [$primary, $standby, $reader] = $database->nodes()->get()->all();
    $primary->update(['role' => NodeRole::Primary]);
    $reader->update(['kind' => NodeKind::ReadOnly]);

    resolve(RemoveDatabaseNode::class)->handle($standby->fresh());
    $database->fresh()->markReady();
    resolve(RemoveDatabaseNode::class)->handle($reader->fresh());

    Queue::assertPushed(RemoveNodeJob::class, 2);
});

it('removes a node from its page and returns to the database', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $reader = withReadOnlyNode($database, ['name' => 'main-ro-1']);
    Queue::fake([RemoveNodeJob::class]);

    $this->delete(projectUrl("/databases/{$database->id}/nodes/{$reader->id}"), ['force' => true])->assertRedirect(projectUrl("/databases/{$database->id}"));

    expect($database->fresh()->status)->toBe(DatabaseStatus::Scaling);
    Queue::assertPushed(RemoveNodeJob::class, fn (RemoveNodeJob $job): bool => $job->node->is($reader) && $job->force);
});

it('only removes nodes of the database in the address', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $other = idleDatabase('valkey', 1, DatabaseMode::Single, 'other');
    $foreign = withReadOnlyNode($other);
    Queue::fake([RemoveNodeJob::class]);

    $this->delete(projectUrl("/databases/{$database->id}/nodes/{$foreign->id}"))->assertNotFound();

    Queue::assertNothingPushed();
});
