<?php

declare(strict_types=1);

use App\DnsProviders\Drivers\Cloudflare\Cloudflare;
use App\DTOs\Dns\DnsRecord;
use App\DTOs\Dns\DnsZoneData;
use App\DTOs\Dns\ServiceTarget;
use App\Enums\Dns\DnsRecordType;
use App\Models\DnsProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('reads every page of zones', function () {
    Http::fake(fn (Request $request) => Http::response([
        'success' => true,
        'result' => [['id' => "zone-{$request['page']}", 'name' => "site{$request['page']}.com"]],
        'result_info' => ['total_pages' => 2],
    ]));

    expect((new Cloudflare(new DnsProvider(['credentials' => ['token' => 'cf-token']])))->zones())->toEqual([
        new DnsZoneData('zone-1', 'site1.com'),
        new DnsZoneData('zone-2', 'site2.com'),
    ]);
    Http::assertSentCount(2);
});

it('reads records of a type and tells the ones databasify created', function () {
    fakeCloudflare([
        ['id' => 'ours', 'content' => '198.51.100.1', 'comment' => 'databasify'],
        ['id' => 'theirs', 'content' => '198.51.100.2'],
    ]);

    expect((new Cloudflare(new DnsProvider(['credentials' => ['token' => 'cf-token']])))->records('zone-1', DnsRecordType::A, 'db.example.com'))->toEqual([
        new DnsRecord(DnsRecordType::A, 'db.example.com', '198.51.100.1', 'ours', true),
        new DnsRecord(DnsRecordType::A, 'db.example.com', '198.51.100.2', 'theirs', false),
    ]);
    Http::assertSent(fn (Request $request): bool => $request['type'] === 'A' && $request['name'] === 'db.example.com');
});

it('reads service and text records in a common form', function () {
    fakeCloudflare([['id' => 'srv', 'comment' => 'databasify', 'content' => '"a=b"', 'data' => ['target' => 'orders.db.example.com', 'port' => 25060]]]);
    $driver = new Cloudflare(new DnsProvider(['credentials' => ['token' => 'cf-token']]));

    expect($driver->records('zone-1', DnsRecordType::Srv, '_mongodb._tcp.orders.db.example.com')[0]->content)->toBe('0 0 25060 orders.db.example.com')
        ->and($driver->records('zone-1', DnsRecordType::Txt, 'orders.db.example.com')[0]->content)->toBe('a=b');
});

it('creates marked records in the shape each type needs and deletes by id', function () {
    fakeCloudflare();
    $driver = new Cloudflare(new DnsProvider(['credentials' => ['token' => 'cf-token']]));

    $driver->createRecord('zone-1', new DnsRecord(DnsRecordType::A, 'db.example.com', '198.51.100.1'));
    $driver->createRecord('zone-1', new DnsRecord(DnsRecordType::Srv, '_mongodb._tcp.orders.db.example.com', (new ServiceTarget('orders.db.example.com', 25060))->toContent()));
    $driver->createRecord('zone-1', new DnsRecord(DnsRecordType::Txt, 'orders.db.example.com', 'replicaSet=databasify'));
    $driver->deleteRecord('zone-1', new DnsRecord(DnsRecordType::A, 'db.example.com', '198.51.100.9', 'old'));

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['type'] === 'A' && $request['content'] === '198.51.100.1' && $request['proxied'] === false && $request['ttl'] === 60 && $request['comment'] === 'databasify');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['type'] === 'SRV' && $request['data'] === ['priority' => 0, 'weight' => 0, 'port' => 25060, 'target' => 'orders.db.example.com']);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['type'] === 'TXT' && $request['content'] === '"replicaSet=databasify"');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/zones/zone-1/dns_records/old'));
});
