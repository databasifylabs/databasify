<?php

declare(strict_types=1);

use App\DTOs\Plugins\PluginAutoload;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\NodeRole;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\DnsProvider;
use App\Models\DnsZone;
use App\Models\Network;
use App\Models\NodeMetricSample;
use App\Models\Plugin;
use App\Models\Server;
use App\Models\StorageProvider;

it('lists only the wireguard members of a network, ordered by id', function () {
    $network = Network::factory()->wireguard()->create();
    $members = Server::factory()->count(2)->create(['network_id' => $network->id, 'wireguard_public_key' => 'key'])->sortBy('id')->values();
    Server::factory()->create(['network_id' => $network->id, 'wireguard_public_key' => null]);
    Server::factory()->create(['wireguard_public_key' => 'other-key']);

    expect($network->wireguardMembers()->pluck('id')->all())->toBe($members->pluck('id')->all());
});

it('lists the databases using a domain of a dns provider', function () {
    $provider = DnsProvider::factory()->create();
    $zone = DnsZone::factory()->create(['dns_provider_id' => $provider->id]);
    $used = idleDatabase('valkey', 1);
    $used->update(['dns_zone_id' => $zone->id]);
    idleDatabase('valkey', 1, name: 'decoy')->update(['dns_zone_id' => DnsZone::factory()->create()->id]);

    expect($provider->databases()->pluck('databases.id')->all())->toBe([$used->id]);
});

it('lists the databases archiving to a storage provider', function () {
    $provider = StorageProvider::factory()->create();
    $archiving = withRecovery(idleDatabase('valkey', 1), provider: $provider);
    withRecovery(idleDatabase('valkey', 1, name: 'decoy'));

    expect($provider->databases()->pluck('databases.id')->all())->toBe([$archiving->id]);
});

it('scopes ready databases, primary nodes, enabled plugins and old samples', function () {
    $ready = idleDatabase('valkey', 1);
    $ready->update(['status' => DatabaseStatus::Ready]);
    idleDatabase('valkey', 1, name: 'decoy')->update(['status' => DatabaseStatus::Degraded]);
    $primary = $ready->seed();
    $primary->update(['role' => NodeRole::Primary]);
    $old = NodeMetricSample::query()->create(['database_node_id' => $primary->id, 'sampled_at' => now()->subDays(2), 'cpu_percent' => 1, 'memory_percent' => 1, 'disk_percent' => 1]);
    NodeMetricSample::query()->create(['database_node_id' => $primary->id, 'sampled_at' => now(), 'cpu_percent' => 1, 'memory_percent' => 1, 'disk_percent' => 1]);
    $enabled = Plugin::query()->create(['id' => 'acme/on', 'name' => 'On', 'version' => '1.0.0', 'source' => 'local', 'class' => 'Acme\\On', 'autoload' => new PluginAutoload([]), 'enabled' => true]);
    Plugin::query()->create(['id' => 'acme/off', 'name' => 'Off', 'version' => '1.0.0', 'source' => 'local', 'class' => 'Acme\\Off', 'autoload' => new PluginAutoload([]), 'enabled' => false]);

    expect(Database::query()->ready()->pluck('id')->all())->toBe([$ready->id])
        ->and(DatabaseNode::query()->primary()->pluck('id')->all())->toBe([$primary->id])
        ->and(Plugin::query()->enabled()->pluck('id')->all())->toBe([$enabled->id])
        ->and(NodeMetricSample::query()->sampledBefore(now()->subDay())->pluck('id')->all())->toBe([$old->id]);
});
