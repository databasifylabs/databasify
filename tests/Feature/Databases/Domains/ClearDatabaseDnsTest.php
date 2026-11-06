<?php

declare(strict_types=1);

use App\Actions\Databases\Domains\ClearDatabaseDns;
use App\Enums\Databases\DatabaseMode;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('removes the records of every database name and of every read-only node', function () {
    fakeCloudflare();
    $database = withZone(idleDatabase('mysql', 1, DatabaseMode::Single, 'app-db'));
    withReadOnlyNode($database, ['name' => 'reports']);

    resolve(ClearDatabaseDns::class)->handle($database);

    $names = collect(Http::recorded(fn (Request $request): bool => $request->method() === 'GET'))->map(fn (array $pair): string => $pair[0]['name']);
    expect($names->sort()->values()->all())->toBe(['app-db.db.example.com', 'private-app-db.db.example.com', 'private-replica-app-db.db.example.com', 'private-reports.db.example.com', 'replica-app-db.db.example.com', 'reports.db.example.com'])
        ->and(Cache::get('dns:app-db.db.example.com'))->toBe([]);
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'DELETE'))->toHaveCount(6);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

it('leaves dns alone for a database without a domain', function () {
    Http::fake();

    resolve(ClearDatabaseDns::class)->handle(idleDatabase('mysql', 1, DatabaseMode::Single));

    Http::assertNothingSent();
});

it('withdraws the mongodb service, its options and every member name', function () {
    fakeCloudflare([]);
    $database = withZone(idleDatabase('mongodb', 2, name: 'orders'));
    $hosts = $database->nodes()->get()->map(fn ($node): string => "orders-node-{$node->position}.db.example.com");

    resolve(ClearDatabaseDns::class)->handle($database);

    $read = collect(Http::recorded(fn (Request $request): bool => $request->method() === 'GET'))->map(fn (array $pair): string => "{$pair[0]['type']} {$pair[0]['name']}");
    expect($read->all())->toContain('SRV _mongodb._tcp.orders.db.example.com', 'TXT orders.db.example.com', "A {$hosts[0]}", "A {$hosts[1]}")
        ->and(Cache::get("dns:{$hosts[0]}"))->toBe([])
        ->and(Cache::get('dns-srv:_mongodb._tcp.orders.db.example.com'))->toBe([]);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});
