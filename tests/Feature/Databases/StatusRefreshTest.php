<?php

declare(strict_types=1);

use App\Actions\Databases\Domains\SyncDatabaseDns;
use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\DTOs\Databases\NodeStatus;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\NodeRole;
use App\Events\DatabaseAlertRaised;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\DnsZone;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\RecordingDriver;

test('a node is marked unreachable only after three missed reads', function () {
    $database = Database::factory()->create();
    $node = DatabaseNode::factory()->for($database)->replica(1)->create();
    RecordingDriver::fake()->on('nodes.status', NodeStatus::unreachable());

    app(RefreshDatabaseStatus::class)->handle($database);
    app(RefreshDatabaseStatus::class)->handle($database);
    expect($node->refresh())->role->toBe(NodeRole::Replica)->missed_status_reads->toBe(2);

    app(RefreshDatabaseStatus::class)->handle($database);
    expect($node->refresh()->role)->toBe(NodeRole::Unknown);
});

test('a silent primary is marked unreachable at once when another member answers as primary', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $database = Database::factory()->highlyAvailable()->create(['name' => 'orders']);
    $oldPrimary = DatabaseNode::factory()->for($database)->create();
    $standby = DatabaseNode::factory()->for($database)->replica()->create();
    RecordingDriver::fake()->on('nodes.status', fn (DatabaseNode $node) => $node->is($oldPrimary) ? NodeStatus::unreachable() : new NodeStatus(NodeRole::Primary, '18.1'));

    app(RefreshDatabaseStatus::class)->handle($database);

    expect($oldPrimary->refresh()->role)->toBe(NodeRole::Unknown)
        ->and($standby->refresh()->role)->toBe(NodeRole::Primary);
    Event::assertDispatched(DatabaseAlertRaised::class, fn (DatabaseAlertRaised $alert) => $alert->subject === 'orders is degraded');
});

test('a failed database is not read', function () {
    $database = Database::factory()->status(DatabaseStatus::Failed)->create();
    DatabaseNode::factory()->for($database)->create();
    $engine = RecordingDriver::fake();

    app(RefreshDatabaseStatus::class)->handle($database);

    expect($engine->called('nodes.status'))->toBeFalse();
});

test('dns follows the primary after a failover', function () {
    $zone = DnsZone::factory()->create(['name' => 'example.com', 'external_id' => 'zone-1']);
    $database = Database::factory()->highlyAvailable()->create(['name' => 'orders', 'dns_zone_id' => $zone->id]);
    $oldPrimary = DatabaseNode::factory()->for($database)->replica(1)->create();
    $newPrimary = DatabaseNode::factory()->for($database)->create(['position' => 2]);
    Http::fake(['https://api.cloudflare.com/client/v4/zones/zone-1/dns_records*' => fn (Request $request) => match (true) {
        $request->method() === 'GET' && ($request->data()['name'] ?? null) === 'orders.db.example.com' => Http::response(['result' => [
            ['id' => 'rec-old', 'content' => $oldPrimary->server->public_ip, 'comment' => 'databasify'],
        ]]),
        $request->method() === 'GET' => Http::response(['result' => []]),
        default => Http::response(['result' => ['id' => 'rec-new']]),
    }]);

    app(SyncDatabaseDns::class)->handle($database);

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/dns_records/rec-old'));
    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request['name'] === 'orders.db.example.com' && $request['content'] === $newPrimary->server->public_ip);
});
