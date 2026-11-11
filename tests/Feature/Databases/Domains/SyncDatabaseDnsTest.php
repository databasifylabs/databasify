<?php

declare(strict_types=1);

use App\Actions\Databases\Domains\SyncDatabaseDns;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\NodeRole;
use App\Enums\Servers\ServerLogStatus;
use App\Models\DatabaseNode;
use App\Models\ServerLog;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

it('points the main names at the primary only and the replica names at every standby', function () {
    fakeCloudflare([]);
    $database = withZone(idleDatabase('mysql', 3, name: 'app-db'));
    markRoles($database, NodeRole::Replica, NodeRole::Primary, NodeRole::Replica);
    [$first, $primary, $third] = $database->nodes()->with('server')->get()->all();

    resolve(SyncDatabaseDns::class)->handle($database);

    expect(Cache::get('dns:app-db.db.example.com'))->toBe([$primary->server->public_ip])
        ->and(Cache::get('dns:private-app-db.db.example.com'))->toBe([$primary->privateIp()])
        ->and(Cache::get('dns:replica-app-db.db.example.com'))->toBe(collect([$first, $third])->map(fn (DatabaseNode $node): string => $node->server->public_ip)->sort()->values()->all())
        ->and(Cache::get('dns:private-replica-app-db.db.example.com'))->toBe(collect([$first, $third])->map(fn (DatabaseNode $node): string => $node->privateIp())->sort()->values()->all());
});

it('moves the main names to the new primary after a failover', function () {
    fakeCloudflare([]);
    $database = withZone(idleDatabase('mysql', 2, name: 'app-db'));
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    [$old, $new] = $database->nodes()->with('server')->get()->all();
    resolve(SyncDatabaseDns::class)->handle($database);

    markRoles($database, NodeRole::Replica, NodeRole::Primary);
    resolve(SyncDatabaseDns::class)->handle($database->fresh() ?? $database);

    expect(Cache::get('dns:app-db.db.example.com'))->toBe([$new->server->public_ip])
        ->and(Cache::get('dns:replica-app-db.db.example.com'))->toBe([$old->server->public_ip]);
});

it('points the replica names at the primary when it has no standbys', function () {
    fakeCloudflare([]);
    $database = withZone(idleDatabase('valkey', 1, DatabaseMode::Single, 'cache'));
    markRoles($database, NodeRole::Primary);

    resolve(SyncDatabaseDns::class)->handle($database);

    expect(Cache::get('dns:replica-cache.db.example.com'))->toBe(Cache::get('dns:cache.db.example.com'))
        ->and(Cache::get('dns:cache.db.example.com'))->toBe([$database->seed()->server->public_ip]);
});

it('gives each named read-only node its own pair of names', function () {
    fakeCloudflare([]);
    $database = withZone(idleDatabase('postgres', 1, DatabaseMode::Single, 'orders'));
    markRoles($database, NodeRole::Primary);
    $reader = withReadOnlyNode($database, ['name' => 'reports', 'role' => NodeRole::Replica]);

    resolve(SyncDatabaseDns::class)->handle($database);

    expect(Cache::get('dns:reports.db.example.com'))->toBe([$reader->server->public_ip])
        ->and(Cache::get('dns:private-reports.db.example.com'))->toBe([$reader->privateIp()])
        ->and(Cache::get('dns:replica-orders.db.example.com'))->toBe([$database->seed()->server->public_ip]);
});

it('leaves the database names alone while no primary is known', function () {
    fakeCloudflare([]);
    $database = withZone(idleDatabase('mysql', 2, name: 'app-db'));
    markRoles($database, NodeRole::Unknown, NodeRole::Unknown);

    resolve(SyncDatabaseDns::class)->handle($database);

    Http::assertNothingSent();
});

it('leaves the database names alone while two members both claim to be primary', function () {
    fakeCloudflare([]);
    $database = withZone(idleDatabase('valkey', 2, name: 'cache'));
    markRoles($database, NodeRole::Primary, NodeRole::Primary);

    resolve(SyncDatabaseDns::class)->handle($database);

    Http::assertNothingSent();
});

it('touches no dns for a database without a domain', function () {
    Http::fake();
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    markRoles($database, NodeRole::Primary);
    withReadOnlyNode($database, ['name' => 'reports']);

    resolve(SyncDatabaseDns::class)->handle($database);

    Http::assertNothingSent();
});

it('touches no dns when the domain was removed after the database was loaded', function () {
    Http::fake();
    $database = withZone(idleDatabase('valkey', 1, DatabaseMode::Single, 'cache'));
    markRoles($database, NodeRole::Primary);
    $database->load('dnsZone');
    $database->newQuery()->whereKey($database->id)->update(['dns_zone_id' => null]);

    resolve(SyncDatabaseDns::class)->handle($database);

    Http::assertNothingSent();
});

it('creates only the records that are missing', function () {
    $database = withZone(idleDatabase('valkey', 1, DatabaseMode::Single, 'cache'));
    markRoles($database, NodeRole::Primary);
    fakeCloudflare([['id' => 'kept', 'content' => $database->seed()->server->public_ip, 'comment' => 'databasify']]);

    resolve(SyncDatabaseDns::class)->handle($database);

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && $request['name'] === 'cache.db.example.com');
});

it('publishes every member of a mongodb database on its own name in the srv record, like digitalocean', function () {
    fakeCloudflare([]);
    $database = withZone(idleDatabase('mongodb', 3, name: 'orders'));
    $reader = withReadOnlyNode($database, ['name' => 'reports']);
    markRoles($database, NodeRole::Primary, NodeRole::Replica, NodeRole::Replica);
    $members = $database->nodes()->members()->with('server')->get();
    $hosts = $members->map(fn (DatabaseNode $node): string => "orders-node-{$node->position}.db.example.com");

    resolve(SyncDatabaseDns::class)->handle($database->fresh() ?? $database);

    expect(Cache::get('dns-srv:_mongodb._tcp.orders.db.example.com'))->toBe($hosts->map(fn (string $host): string => "{$host}:25060")->all())
        ->and(Cache::get('dns-txt:orders.db.example.com'))->toBe(['replicaSet=databasify&authSource=admin'])
        ->and(Cache::get("dns:{$hosts[1]}"))->toBe([$members[1]->server->public_ip])
        ->and(Cache::has("dns:orders-node-{$reader->position}.db.example.com"))->toBeFalse()
        ->and($database->certificateNames())->toContain(...$hosts->all())
        ->and($database->connectionProfile()->toArray()['service'])->toMatchArray(['uri_scheme' => 'mongodb+srv', 'uri_port' => false])
        ->and($database->connectionProfile()->uriScheme)->toBe('mongodb');
});

it('publishes no service for other engines or for databases without a domain', function () {
    fakeCloudflare([]);
    $database = withZone(idleDatabase('mysql', 1, DatabaseMode::Single, 'app-db'));
    markRoles($database, NodeRole::Primary);

    resolve(SyncDatabaseDns::class)->handle($database);

    expect(Http::recorded(fn (Request $request): bool => in_array($request['type'] ?? null, ['SRV', 'TXT'], true)))->toBeEmpty()
        ->and($database->connectionProfile()->service)->toBeNull()
        ->and(idleDatabase('mongodb', 1, DatabaseMode::Single, 'orders')->connectionProfile()->service)->toBeNull();
});

it('retries a failed cloudflare request and syncs the records', function () {
    Sleep::fake();
    $database = withZone(idleDatabase('mysql', 2, name: 'app-db'));
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    $remaining = $database->nodes()->with('server')->get()->last();
    Http::fake([
        'api.cloudflare.com/client/v4/zones/zone-1/dns_records/removed' => Http::sequence()
            ->push(['success' => false, 'errors' => [['message' => 'Service unavailable']]], 503)
            ->whenEmpty(Http::response(['success' => true, 'result' => []])),
        'api.cloudflare.com/client/v4/zones/zone-1/dns_records?*' => Http::response(['success' => true, 'result' => [['id' => 'removed', 'content' => '203.0.113.9', 'comment' => 'databasify']]]),
        'api.cloudflare.com/client/v4/zones/zone-1/dns_records*' => Http::response(['success' => true, 'result' => []]),
    ]);

    resolve(SyncDatabaseDns::class)->handle($database);

    expect(Cache::get('dns:replica-app-db.db.example.com'))->toBe([$remaining?->server->public_ip])
        ->and(ServerLog::query()->where('status', ServerLogStatus::Failed)->exists())->toBeFalse();
});

it('reports a dns problem after the retries run out', function () {
    Sleep::fake();
    $database = withZone(idleDatabase('mysql', 2, name: 'app-db'));
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    Http::fake(['api.cloudflare.com/*' => Http::failedConnection()]);

    resolve(SyncDatabaseDns::class)->handle($database);

    $problem = ServerLog::query()->where('database_id', $database->id)->where('status', ServerLogStatus::Failed)->sole();
    expect($problem->name)->toBe('Sync DNS records')
        ->and($problem->output)->toContain('Cloudflare could not be reached.')
        ->and(Cache::has('dns:replica-app-db.db.example.com'))->toBeFalse();
    Http::assertSentCount(4);
});

it('logs a failing dns sync once until it recovers', function () {
    Sleep::fake();
    $database = withZone(idleDatabase('mysql', 2, name: 'app-db'));
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    $failures = fn (): int => ServerLog::query()->where('database_id', $database->id)->where('status', ServerLogStatus::Failed)->count();
    $reachable = false;
    Http::fake(function () use (&$reachable) {
        return $reachable ? Http::response(['success' => true, 'result' => []]) : Http::failedConnection();
    });
    $sync = fn () => resolve(SyncDatabaseDns::class)->handle($database);

    $sync();
    $sync();
    expect($failures())->toBe(1);

    $reachable = true;
    $sync();
    Cache::forget('dns:app-db.db.example.com');
    $reachable = false;
    $sync();

    expect($failures())->toBe(2);
});

it('leaves the records alone while the domain of the database changes', function () {
    Sleep::fake(syncWithCarbon: true);
    fakeCloudflare([]);
    $database = withZone(idleDatabase('mysql', 1, DatabaseMode::Single, 'app-db'));
    $database->dnsLock()->get();

    resolve(SyncDatabaseDns::class)->handle($database);

    Http::assertNothingSent();
});

it('waits for a sync that already runs instead of dropping its own', function () {
    Sleep::fake();
    fakeCloudflare([]);
    $database = withZone(idleDatabase('mysql', 1, DatabaseMode::Single, 'app-db'));
    markRoles($database, NodeRole::Primary);
    $running = $database->dnsLock();
    $running->get();
    Sleep::whenFakingSleep(fn () => $running->release());

    resolve(SyncDatabaseDns::class)->handle($database);

    expect(Cache::get('dns:app-db.db.example.com'))->toBe([$database->nodes()->sole()->server->public_ip]);
});
