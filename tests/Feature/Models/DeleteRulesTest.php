<?php

declare(strict_types=1);

use App\Enums\Databases\PoolMode;
use App\Enums\Databases\ResourceMetric;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\DnsProvider;
use App\Models\DnsZone;
use App\Models\Server;
use App\Models\ServerLog;

test('deleting a database deletes its accounts, nodes and network rules and keeps its server logs', function () {
    $database = Database::factory()->create();
    $node = DatabaseNode::factory()->for($database)->create();
    $user = $database->users()->create(['name' => 'app', 'password' => 'secret']);
    $schema = $database->schemas()->create(['name' => 'app']);
    $source = $database->trustedSources()->create(['cidr' => '203.0.113.7/32']);
    $pool = $database->connectionPools()->create(['name' => 'app', 'schema' => 'app', 'mode' => PoolMode::Transaction, 'size' => 10]);
    $alert = $node->alerts()->create(['metric' => ResourceMetric::Cpu, 'opened_at' => now()]);
    $log = ServerLog::factory()->for($node->server)->create(['database_id' => $database->id]);

    $database->delete();

    collect([$node, $user, $schema, $source, $pool, $alert])->each(fn ($child) => $this->assertModelMissing($child));
    $this->assertModelExists($node->server);
    expect($log->refresh()->database_id)->toBeNull();
});

test('deleting a server deletes its users and logs', function () {
    $server = Server::factory()->create();
    $user = $server->users()->create(['username' => 'deploy']);
    $log = ServerLog::factory()->for($server)->create();

    $server->delete();

    $this->assertModelMissing($user);
    $this->assertModelMissing($log);
});

test('deleting a dns provider deletes its zones', function () {
    $provider = DnsProvider::factory()->create();
    $zone = DnsZone::factory()->for($provider, 'provider')->create();

    $provider->delete();

    $this->assertModelMissing($zone);
});
