<?php

declare(strict_types=1);

use App\Actions\Databases\Nodes\AddDatabaseNode;
use App\Actions\Databases\Nodes\JoinDatabaseNode;
use App\DTOs\Databases\NewDatabaseNode;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\NodeKind;
use App\Enums\Databases\NodeRole;
use App\Enums\Servers\ServerLogStatus;
use App\Exceptions\Databases\OperationStopped;
use App\Exceptions\Databases\WorkLeftUndone;
use App\Exceptions\Ssh\RemoteScriptFailed;
use App\Jobs\Databases\AddDatabaseNodeJob;
use App\Models\DatabaseNode;
use App\Models\Server;
use App\Models\ServerLog;
use Illuminate\Support\Facades\Queue;

it('undoes a failed join everywhere it reached', function () {
    $database = idleDatabase('mysql', 2);
    $database->nodes()->first()?->update(['role' => NodeRole::Primary]);
    Queue::fake([AddDatabaseNodeJob::class]);
    $server = Server::factory()->ready()->create();
    resolve(AddDatabaseNode::class)->handle($database, new NewDatabaseNode($server));
    $joining = $database->nodes()->where('server_id', $server->id)->sole();
    $ssh = fakeSsh()->respond()->respond()->respond()->respond('mysql stopped while joining', 1);

    expect(fn () => resolve(JoinDatabaseNode::class)->handle($database, $joining))->toThrow(RemoteScriptFailed::class);

    $scripts = collect($ssh->runs)->pluck('script')->implode("\n----\n");
    expect($database->nodes()->count())->toBe(2)
        ->and($scripts)->toContain('rm -rf "$dir"')
        ->and($scripts)->toContain("remove_instance('{$joining->privateIp()}:25060'")
        ->and($scripts)->not->toContain('set_env_key');
});

it('records the role of the joined node before the operation finishes', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    Queue::fake([AddDatabaseNodeJob::class]);
    $server = Server::factory()->ready()->create();
    resolve(AddDatabaseNode::class)->handle($database, new NewDatabaseNode($server, NodeKind::ReadOnly, 'reports'));
    $reader = $database->nodes()->readOnly()->sole();
    $ssh = fakeSsh()->respond(certificateOutput('ca.crt', 'server.pem', 'ca.key'));
    foreach (range(1, 9) as $ignored) {
        $ssh->respond();
    }
    $ssh->respond("role:master\n")->respond("role:slave\nmaster_link_status:up\nmaster_sync_in_progress:0\n");
    $operationWhenRecorded = null;
    DatabaseNode::updated(function (DatabaseNode $node) use ($reader, $database, &$operationWhenRecorded): void {
        if ($node->is($reader) && $node->role === NodeRole::Replica) {
            $operationWhenRecorded = $database->fresh()->operation;
        }
    });

    runJob(new AddDatabaseNodeJob($database, $reader));

    expect($operationWhenRecorded)->toBe('Add Read-only node reports')
        ->and($database->fresh()->operation)->toBeNull();
});

it('pulls the image on the joining node in the same pass that opens peer ports', function () {
    $database = idleDatabase('mysql', 3);
    markRoles($database, NodeRole::Primary, NodeRole::Replica, NodeRole::Replica);
    Queue::fake([AddDatabaseNodeJob::class]);
    $server = Server::factory()->ready()->create();
    resolve(AddDatabaseNode::class)->handle($database, new NewDatabaseNode($server, NodeKind::ReadOnly, 'reports'));
    $reader = $database->nodes()->readOnly()->sole();
    fakeSsh();

    rescue(fn () => resolve(JoinDatabaseNode::class)->handle($database, $reader), report: false);

    $pass = ServerLog::query()->where('group_name', 'opening peer ports on 3 nodes');
    expect((clone $pass)->count())->toBe(4)
        ->and((clone $pass)->where('name', 'like', '%pulling%')->count())->toBe(1);
});

it('keeps a node that joined when a later step on a peer fails and fails the operation naming the repair', function () {
    $database = idleDatabase('mongodb', 2);
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    [, $peer] = $database->nodes()->get()->all();
    Queue::fake([AddDatabaseNodeJob::class]);
    $server = Server::factory()->ready()->create();
    resolve(AddDatabaseNode::class)->handle($database, new NewDatabaseNode($server));
    $joining = $database->nodes()->where('server_id', $server->id)->sole();
    $ssh = fakeSsh()->respond(certificateOutput('ca.crt', 'server.pem', 'ca.key'))
        ->respondWhen(fn (string $on, string $script): bool => $on === $peer->server->name && str_contains($script, 'membership is reconciled there'), 'ssh: connect timed out', 255)
        ->respondWhen(fn (string $on, string $script): bool => $on === $server->name && str_contains($script, "print('version:' + db.version())"), "role:secondary\nversion:9.0.1\n");
    $expected = "{$server->name} joined main as replica and is kept, but {$peer->server->name}: updating membership on {$peer->server->name} failed with exit code 255. Remove {$server->name} or redeploy main to finish.";

    expect(fn () => runJob(new AddDatabaseNodeJob($database, $joining)))->toThrow(RuntimeException::class, $expected)
        ->and($database->fresh()->operation)->toBeNull()
        ->and($database->nodes()->count())->toBe(3)
        ->and(collect($ssh->runs)->pluck('script')->implode("\n"))->not->toContain('docker rm -v -f')
        ->and($joining->fresh()->role)->toBe(NodeRole::Replica)
        ->and($database->fresh()->mode)->toBe(DatabaseMode::HighAvailability)
        ->and(ServerLog::query()->where('server_id', $server->id)->where('name', "{$server->name}: checking whether it joined")->exists())->toBeTrue()
        ->and(ServerLog::query()->where('server_id', $server->id)->where('status', ServerLogStatus::Failed)->where('output', 'like', '%'.$expected.'%')->exists())->toBeTrue();
});

it('cleans up a node that never joined when the user stops the add', function () {
    $database = idleDatabase('mongodb', 2);
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    [$primary, $peer] = $database->nodes()->get()->all();
    Queue::fake([AddDatabaseNodeJob::class]);
    $server = Server::factory()->ready()->create();
    resolve(AddDatabaseNode::class)->handle($database, new NewDatabaseNode($server));
    $joining = $database->nodes()->where('server_id', $server->id)->sole();
    $ssh = fakeSsh()->respond(certificateOutput('ca.crt', 'server.pem', 'ca.key'))
        ->respondWhen(function (string $on, string $script) use ($database, $peer): bool {
            if ($on === $peer->server->name && str_contains($script, 'membership is reconciled there')) {
                $database->requestStop();
            }

            return false;
        });

    expect(fn () => runJob(new AddDatabaseNodeJob($database, $joining)))->toThrow(OperationStopped::class);

    $runs = collect($ssh->runs);
    expect($database->nodes()->count())->toBe(2)
        ->and($database->fresh()->operation)->toBeNull()
        ->and($runs->contains(fn (array $run): bool => $run['server'] === $server->name && str_contains($run['script'], 'docker rm -v -f databasify-mongodb')))->toBeTrue()
        ->and($runs->contains(fn (array $run): bool => $run['server'] === $primary->server->name && str_contains($run['script'], "const host = '{$joining->privateIp()}")))->toBeTrue();
});

it('rolls back a node that never joined even when the failure came after it started', function () {
    $database = idleDatabase('mongodb', 2);
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    [, $peer] = $database->nodes()->get()->all();
    Queue::fake([AddDatabaseNodeJob::class]);
    $server = Server::factory()->ready()->create();
    resolve(AddDatabaseNode::class)->handle($database, new NewDatabaseNode($server));
    $joining = $database->nodes()->where('server_id', $server->id)->sole();
    fakeSsh()->respond(certificateOutput('ca.crt', 'server.pem', 'ca.key'))
        ->respondWhen(fn (string $on, string $script): bool => $on === $peer->server->name && str_contains($script, 'membership is reconciled there'), 'ssh: connect timed out', 255);

    expect(fn () => resolve(JoinDatabaseNode::class)->handle($database, $joining))->toThrow(WorkLeftUndone::class, "{$server->name} was removed, but {$peer->server->name}: updating membership")
        ->and($database->nodes()->count())->toBe(2);
});

it('finishes the rollback when a peer is unreachable and logs what it could not clean', function () {
    $database = idleDatabase('mysql', 2);
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    [$primary] = $database->nodes()->get()->all();
    Queue::fake([AddDatabaseNodeJob::class]);
    $server = Server::factory()->ready()->create();
    resolve(AddDatabaseNode::class)->handle($database, new NewDatabaseNode($server));
    $joining = $database->nodes()->where('server_id', $server->id)->sole();
    fakeSsh()->respondWhen(fn (string $on): bool => $on === $primary->server->name, 'ssh: connect timed out', 255);

    expect(fn () => runJob(new AddDatabaseNodeJob($database, $joining)))->toThrow(RuntimeException::class, "{$server->name} was removed, but {$primary->server->name}: removing {$server->name} from the cluster on {$primary->server->name} failed")
        ->and($database->nodes()->count())->toBe(2)
        ->and($database->fresh()->operation)->toBeNull()
        ->and(ServerLog::query()->where('server_id', $primary->server_id)->where('status', ServerLogStatus::Failed)->where('name', 'like', '%removing%')->exists())->toBeTrue();
});
