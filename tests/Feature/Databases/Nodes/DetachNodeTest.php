<?php

declare(strict_types=1);

use App\Actions\Databases\Nodes\DetachNode;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\NodeRole;
use App\Exceptions\Databases\WorkLeftUndone;
use App\Exceptions\Ssh\RemoteScriptFailed;
use App\Models\DatabaseNode;
use App\Models\ServerLog;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('removes the dns records and certificate of a detached read-only node', function () {
    fakeCloudflare();
    $database = withZone(idleDatabase('valkey', 1, DatabaseMode::Single));
    $reader = withReadOnlyNode($database, ['name' => 'reports']);
    $database->update(['settings' => $database->settings->withNodeCertificates($reader->id, ['server.pem' => 'pem'])]);
    fakeSsh();

    resolve(DetachNode::class)->handle($database, $reader);

    expect($database->fresh()->settings->nodeCertificate($reader->id, 'server.pem'))->toBeNull()
        ->and($database->nodes()->readOnly()->exists())->toBeFalse();
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'DELETE'))->toHaveCount(2);
});

it('keeps an unreachable node unless forced and then forgets it on the primary', function () {
    $database = idleDatabase('mysql', 3);
    markRoles($database, NodeRole::Replica, NodeRole::Primary, NodeRole::Replica);
    [, $primary, $dead] = $database->nodes()->get()->all();
    $ssh = fakeSsh()->respond()->respond('ssh: connect timed out', 255);

    expect(fn () => resolve(DetachNode::class)->handle($database, $dead))->toThrow(RemoteScriptFailed::class)
        ->and($database->nodes()->count())->toBe(3)
        ->and($ssh->runs[0]['server'])->toBe($primary->server->name);

    $ssh->respond()->respond('ssh: connect timed out', 255);
    resolve(DetachNode::class)->handle($database, $dead, force: true);

    expect($database->nodes()->count())->toBe(2)
        ->and($ssh->runs[2]['server'])->toBe($primary->server->name)
        ->and($ssh->scriptAt(2))->toContain("remove_instance('{$dead->privateIp()}:25060'")
        ->and(ServerLog::query()->where('server_id', $dead->server_id)->where('output', 'like', "%{$dead->server->name} was removed; its server could not be reached, so its data was not erased%Wipe or destroy {$dead->server->name}.%")->exists())->toBeTrue();
});

it('resets sentinel on the remaining nodes so they forget the removed one', function () {
    $database = idleDatabase('valkey', 3);
    [, , $leaving] = $database->nodes()->get()->all();
    $ssh = fakeSsh();

    resolve(DetachNode::class)->handle($database, $leaving);

    $resets = collect($ssh->runs)->filter(fn (array $run): bool => str_contains($run['script'], 'SENTINEL RESET main'));
    expect($resets)->toHaveCount(2)
        ->and($resets->pluck('server'))->not->toContain($leaving->server->name)
        ->and($resets->first()['script'])->toContain('-ge 1 ]');
});

it('leaves sentinel alone on the last node, where it may never have run and clustering is turned off next', function () {
    $database = idleDatabase('valkey', 2);
    [, $leaving] = $database->nodes()->get()->all();
    $ssh = fakeSsh();

    resolve(DetachNode::class)->handle($database, $leaving);

    expect(collect($ssh->runs)->filter(fn (array $run): bool => str_contains($run['script'], 'SENTINEL RESET main')))->toBeEmpty();
});

it('ends every failure it reports as a sentence', function () {
    $database = idleDatabase('mysql', 3);
    markRoles($database, NodeRole::Replica, NodeRole::Primary, NodeRole::Replica);
    $dead = $database->nodes()->get()->last();
    fakeSsh()->failWith(new RuntimeException('connection reset'));

    expect(fn () => resolve(DetachNode::class)->handle($database, $dead, force: true))
        ->toThrow(WorkLeftUndone::class, "{$dead->server->name} was removed, but connection reset. ");

    expect(ServerLog::query()->where('server_id', $dead->server_id)->pluck('output')->implode("\n"))
        ->toContain("so its data was not erased: connection reset. Wipe or destroy {$dead->server->name}.");
});

it('finishes the peer steps after forgetting the node even when a stop is requested then', function () {
    $database = idleDatabase('mysql', 3);
    markRoles($database, NodeRole::Primary, NodeRole::Replica, NodeRole::Replica);
    $leaving = $database->nodes()->get()->last();
    $ssh = fakeSsh();
    $runsAtForget = null;
    DatabaseNode::deleted(function () use ($database, $ssh, &$runsAtForget): void {
        $runsAtForget = count($ssh->runs);
        $database->requestStop();
    });

    resolve(DetachNode::class)->handle($database, $leaving);

    expect(count($ssh->runs))->toBeGreaterThan($runsAtForget)
        ->and(ServerLog::query()->where('name', 'like', '%Stopped by you%')->orWhere('output', 'like', '%Stopped by you%')->exists())->toBeFalse()
        ->and($database->isStopRequested())->toBeTrue();
});
