<?php

declare(strict_types=1);

use App\DTOs\Dns\ServiceTarget;
use App\Exceptions\Providers\ProviderRequestFailed;
use App\Models\DnsZone;
use App\Services\Dns\DnsRecordPublisher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('replaces stale records with marked ones and remembers the addresses it pointed at', function () {
    fakeCloudflare();

    resolve(DnsRecordPublisher::class)->point(DnsZone::factory()->create(), 'db.example.com', ['198.51.100.1', '198.51.100.2']);

    expect(Cache::get('dns:db.example.com'))->toBe(['198.51.100.1', '198.51.100.2']);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/zones/zone-1/dns_records/old') && $request->hasHeader('Authorization', 'Bearer cf-token'));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['name'] === 'db.example.com' && $request['content'] === '198.51.100.2' && $request['ttl'] === 60 && $request['proxied'] === false && $request['comment'] === 'databasify');
});

it('keeps records that already hold a wanted address', function () {
    fakeCloudflare([['id' => 'kept', 'content' => '198.51.100.1', 'comment' => 'databasify']]);

    resolve(DnsRecordPublisher::class)->point(DnsZone::factory()->create(), 'db.example.com', ['198.51.100.1']);

    Http::assertNotSent(fn (Request $request): bool => in_array($request->method(), ['DELETE', 'POST'], true));
});

it('skips the provider when the addresses have not changed', function () {
    Http::fake();
    Cache::forever('dns:db.example.com', ['198.51.100.1']);

    resolve(DnsRecordPublisher::class)->point(DnsZone::factory()->create(), 'db.example.com', ['198.51.100.1']);

    Http::assertNothingSent();
});

it('remembers nothing when the provider refuses the change', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false, 'errors' => [['message' => 'Authentication error']]], 403)]);

    expect(fn () => resolve(DnsRecordPublisher::class)->point(DnsZone::factory()->create(), 'db.example.com', ['198.51.100.1']))->toThrow(ProviderRequestFailed::class, 'Authentication error')
        ->and(Cache::has('dns:db.example.com'))->toBeFalse();
});

it('checks the provider again after an hour so records changed outside the app are restored', function () {
    fakeCloudflare();
    $zone = DnsZone::factory()->create();
    resolve(DnsRecordPublisher::class)->point($zone, 'db.example.com', ['198.51.100.1']);

    $this->travel(59)->minutes();
    resolve(DnsRecordPublisher::class)->point($zone, 'db.example.com', ['198.51.100.1']);
    $this->travel(2)->minutes();
    resolve(DnsRecordPublisher::class)->point($zone, 'db.example.com', ['198.51.100.1']);

    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'))->toHaveCount(2);
});

it('updates one name at a time and frees it afterwards', function () {
    fakeCloudflare();

    resolve(DnsRecordPublisher::class)->point(DnsZone::factory()->create(), 'db.example.com', ['198.51.100.1']);

    expect(Cache::lock('dns-lock:db.example.com', 1)->get())->toBeTrue();
});

it('keeps only the wanted service target and leaves records it did not create', function () {
    fakeCloudflare([
        ['id' => 'stale', 'comment' => 'databasify', 'data' => ['target' => 'old.db.example.com', 'port' => 25060]],
        ['id' => 'theirs', 'data' => ['target' => 'legacy.example.com', 'port' => 27017]],
    ]);

    resolve(DnsRecordPublisher::class)->pointService(DnsZone::factory()->create(), '_mongodb._tcp.orders.db.example.com', [new ServiceTarget('orders.db.example.com', 25060)]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/dns_records/stale'));
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/dns_records/theirs'));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['type'] === 'SRV' && $request['data'] === ['priority' => 0, 'weight' => 0, 'port' => 25060, 'target' => 'orders.db.example.com']);
});

it('keeps exactly one txt record per described name and leaves it when it already matches', function () {
    fakeCloudflare([['id' => 'current', 'comment' => 'databasify', 'content' => '"replicaSet=databasify&authSource=admin"']]);
    $zone = DnsZone::factory()->create();

    resolve(DnsRecordPublisher::class)->describe($zone, 'orders.db.example.com', 'replicaSet=databasify&authSource=admin');
    Http::assertSentCount(1);

    resolve(DnsRecordPublisher::class)->describe($zone, 'orders.db.example.com', 'replicaSet=other');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/dns_records/current'));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['type'] === 'TXT' && $request['content'] === '"replicaSet=other"');
});

it('holds its lock longer than a sync whose provider calls all retry', function () {
    $stolen = null;
    Http::fake(function () use (&$stolen) {
        if ($stolen === null) {
            $this->travel(90)->seconds();
            $stolen = Cache::lock('dns:db.example.com:lock', 10)->get();
        }

        return Http::response(['success' => true, 'result' => []]);
    });

    resolve(DnsRecordPublisher::class)->point(DnsZone::factory()->create(), 'db.example.com', ['198.51.100.1']);

    expect($stolen)->toBeFalse();
});
