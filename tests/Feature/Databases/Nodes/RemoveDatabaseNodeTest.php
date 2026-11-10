<?php

declare(strict_types=1);

use App\Actions\Databases\Nodes\RemoveDatabaseNode;
use App\Engines\Flows\RemoveNodeFlow;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\NodeKind;
use App\Enums\Databases\NodeRole;
use App\Jobs\Databases\AdvanceDatabaseFlow;
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
    Queue::fake([AdvanceDatabaseFlow::class]);
    markRoles($database, NodeRole::Primary, NodeRole::Replica, NodeRole::Replica);
    [$primary, $standby] = $database->nodes()->orderBy('position')->get()->all();

    resolve(RemoveDatabaseNode::class)->handle($primary->fresh());

    expect($database->flows()->running()->sole()->context)->targetNodeId->toBe($primary->id)->failoverTo->toBe($standby->id);
});

it('lets standby and read-only nodes be removed', function () {
    $database = idleDatabase('valkey', 3);
    Queue::fake([AdvanceDatabaseFlow::class]);
    [$primary, $standby, $reader] = $database->nodes()->get()->all();
    $primary->update(['role' => NodeRole::Primary]);
    $reader->update(['kind' => NodeKind::ReadOnly]);

    resolve(RemoveDatabaseNode::class)->handle($standby->fresh());
    $database->fresh()->finishOperation();
    resolve(RemoveDatabaseNode::class)->handle($reader->fresh());

    expect($database->flows()->where('flow', RemoveNodeFlow::class)->count())->toBe(2);
    Queue::assertPushed(AdvanceDatabaseFlow::class, 2);
});

it('removes a node from its page and returns to the database', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $reader = withReadOnlyNode($database, ['name' => 'main-ro-1']);
    Queue::fake([AdvanceDatabaseFlow::class]);

    $this->delete(projectUrl("/databases/{$database->id}/nodes/{$reader->id}"), ['force' => true])->assertRedirect(projectUrl("/databases/{$database->id}"));

    expect($database->fresh()->operation)->toBe('Remove main-ro-1');
    expect($database->flows()->running()->sole()->context)->targetNodeId->toBe($reader->id)->force->toBeTrue();
});

it('only removes nodes of the database in the address', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $other = idleDatabase('valkey', 1, DatabaseMode::Single, 'other');
    $foreign = withReadOnlyNode($other);
    Queue::fake([AdvanceDatabaseFlow::class]);

    $this->delete(projectUrl("/databases/{$database->id}/nodes/{$foreign->id}"))->assertNotFound();

    Queue::assertNothingPushed();
});
