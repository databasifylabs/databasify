<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\NodeKind;
use App\Models\ConnectionPool;
use App\Models\Server;
use App\Models\TrustedSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('keeps the main trusted sources apart from a read-only node own list', function () {
    $database = idleDatabase('postgres', 1);
    $reader = withReadOnlyNode($database);
    $database->trustedSources()->create(['cidr' => '203.0.113.0/24']);
    $reader->trustedSources()->create(['database_id' => $database->id, 'cidr' => '198.51.100.7/32']);

    expect($database->trustedSources()->pluck('cidr')->all())->toBe(['203.0.113.0/24'])
        ->and($reader->trustedSources()->pluck('cidr')->all())->toBe(['198.51.100.7/32']);
});

it('deletes a removed read-only node own trusted sources and pools', function () {
    $database = idleDatabase('postgres', 1);
    $reader = withReadOnlyNode($database);
    $reader->trustedSources()->create(['database_id' => $database->id, 'cidr' => '198.51.100.7/32']);
    $reader->connectionPools()->create(['database_id' => $database->id, 'name' => 'reports', 'schema' => 'app', 'mode' => 'transaction', 'size' => 10]);

    $reader->delete();

    expect(TrustedSource::count())->toBe(0)->and(ConnectionPool::count())->toBe(0);
});

it('gives existing read-only nodes a copy of the database access and config', function () {
    $file = collect(scandir(database_path('migrations')))->first(fn (string $file): bool => str_contains($file, 'make_read_only_nodes_independent'));
    $migration = require database_path("migrations/{$file}");
    $database = idleDatabase('postgres', 1);
    $reader = withReadOnlyNode($database);
    $migration->down();
    DB::table('trusted_sources')->insert(['id' => (string) Str::uuid(), 'database_id' => $database->id, 'cidr' => '203.0.113.0/24', 'created_at' => now(), 'updated_at' => now()]);
    $database->update(['settings' => $database->settings->withConfig(['work_mem' => '16MB'])]);

    $migration->up();

    $copy = $reader->fresh();
    expect($copy?->status)->toBe(DatabaseStatus::Ready)
        ->and($copy?->config)->toBe(['work_mem' => '16MB'])
        ->and($copy?->trustedSources()->pluck('cidr')->all())->toBe(['203.0.113.0/24'])
        ->and($database->trustedSources()->pluck('cidr')->all())->toBe(['203.0.113.0/24']);
});

it('starts a new read-only node with a copy of the main access and config', function () {
    $database = idleDatabase('postgres', 1);
    $database->update(['settings' => $database->settings->withConfig(['work_mem' => '16MB'])]);
    $database->trustedSources()->create(['cidr' => '203.0.113.0/24']);
    $database->connectionPools()->create(['name' => 'app', 'schema' => 'app', 'mode' => 'transaction', 'size' => 10]);

    $reader = $database->addNode(Server::factory()->ready()->create()->id, NodeKind::ReadOnly, 'reports');

    expect($reader->status)->toBe(DatabaseStatus::Ready)
        ->and($reader->config)->toBe(['work_mem' => '16MB'])
        ->and($reader->trustedSources()->pluck('cidr')->all())->toBe(['203.0.113.0/24'])
        ->and($reader->connectionPools()->pluck('name')->all())->toBe(['app'])
        ->and($database->trustedSources()->count())->toBe(1);
});
