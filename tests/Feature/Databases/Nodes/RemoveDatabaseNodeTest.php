<?php

declare(strict_types=1);

use App\Actions\Databases\Nodes\LeaveDatabaseNode;
use App\Actions\Databases\Nodes\RemoveDatabaseNode;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\NodeKind;
use App\Enums\Databases\NodeRole;
use App\Exceptions\Ssh\RemoteScriptFailed;
use App\Jobs\Databases\RemoveDatabaseNodeJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

it('refuses to remove the primary or the last member', function () {
    $database = idleDatabase('valkey', 2);
    [$primary, $standby] = $database->nodes()->get()->all();
    $primary->update(['role' => NodeRole::Primary]);
    $standby->update(['role' => NodeRole::Replica, 'kind' => NodeKind::ReadOnly]);

    expect(fn () => resolve(RemoveDatabaseNode::class)->handle($primary->fresh()))->toThrow(ValidationException::class)
        ->and(fn () => resolve(RemoveDatabaseNode::class)->handle($primary->fresh()->fill(['role' => NodeRole::Replica])))->toThrow(ValidationException::class, 'only member');
});

it('lets standby and read-only nodes be removed', function () {
    $database = idleDatabase('valkey', 3);
    Queue::fake([RemoveDatabaseNodeJob::class]);
    [$primary, $standby, $reader] = $database->nodes()->get()->all();
    $primary->update(['role' => NodeRole::Primary]);
    $reader->update(['kind' => NodeKind::ReadOnly]);

    resolve(RemoveDatabaseNode::class)->handle($standby->fresh());
    $database->fresh()->finishOperation();
    resolve(RemoveDatabaseNode::class)->handle($reader->fresh());

    Queue::assertPushed(RemoveDatabaseNodeJob::class, 2);
});

it('removes an unreachable node only when forced', function () {
    $database = idleDatabase('valkey', 3);
    $dead = $database->nodes()->get()[2];
    $ssh = fakeSsh()->respond("role:master\n")->respond("role:slave\nmaster_link_status:up\nmaster_sync_in_progress:0\n")->respond('', 255)->respond('ssh: connect timed out', 255);

    $database->startOperation('Remove');

    expect(fn () => runJob(new RemoveDatabaseNodeJob($dead)))->toThrow(RemoteScriptFailed::class)
        ->and($database->nodes()->count())->toBe(3)
        ->and($database->fresh()->operation)->toBeNull();

    $ssh->respond("role:master\n")->respond("role:slave\nmaster_link_status:up\nmaster_sync_in_progress:0\n")->respond('', 255)->respond('ssh: connect timed out', 255);
    resolve(LeaveDatabaseNode::class)->handle($dead->fresh() ?? $dead, force: true);

    expect($database->nodes()->count())->toBe(2)
        ->and(collect($ssh->runs)->pluck('script')->implode("\n"))->toContain("grep -c '^name$'");
});

it('removes a node from its page and returns to the database', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $reader = withReadOnlyNode($database, ['name' => 'main-ro-1']);
    Queue::fake([RemoveDatabaseNodeJob::class]);

    $this->delete("/databases/{$database->id}/nodes/{$reader->id}", ['force' => true])->assertRedirect("/databases/{$database->id}");

    expect($database->fresh()->operation)->toBe('Remove main-ro-1');
    Queue::assertPushed(RemoveDatabaseNodeJob::class, fn (RemoveDatabaseNodeJob $job): bool => $job->node->is($reader) && $job->force);
});

it('shows why the primary cannot be removed', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('valkey', 2);
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    $primary = $database->seed();
    Queue::fake([RemoveDatabaseNodeJob::class]);

    $this->delete("/databases/{$database->id}/nodes/{$primary->id}")->assertSessionHasErrors(['node' => "{$primary->server->name} is the primary, fail over first."]);

    Queue::assertNothingPushed();
});

it('only removes nodes of the database in the address', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $other = idleDatabase('valkey', 1, DatabaseMode::Single, 'other');
    $foreign = withReadOnlyNode($other);
    Queue::fake([RemoveDatabaseNodeJob::class]);

    $this->delete("/databases/{$database->id}/nodes/{$foreign->id}")->assertNotFound();

    Queue::assertNothingPushed();
});
