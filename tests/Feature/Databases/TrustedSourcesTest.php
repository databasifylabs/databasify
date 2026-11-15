<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\ApplyNodeTrustedSourcesJob;
use App\Jobs\Databases\ApplyTrustedSourcesJob;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('a single address is trusted as a /32 and applied', function () {
    Queue::fake([ApplyTrustedSourcesJob::class]);
    $database = Database::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('databases.trusted-sources.store', [$database->project_id, $database]), ['cidr' => ' 203.0.113.7 ', 'label' => 'office'])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('trusted_sources', ['database_id' => $database->id, 'database_node_id' => null, 'cidr' => '203.0.113.7/32', 'label' => 'office']);
    expect($database->refresh()->status)->toBe(DatabaseStatus::Updating);
    Queue::assertPushed(ApplyTrustedSourcesJob::class);
});

test('trusted sources must be ipv4 ranges', function (string $cidr) {
    Queue::fake([ApplyTrustedSourcesJob::class]);
    $database = Database::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('databases.trusted-sources.store', [$database->project_id, $database]), ['cidr' => $cidr])
        ->assertSessionHasErrors(['cidr' => 'Enter an IPv4 address or CIDR range, like 203.0.113.7 or 203.0.113.0/24.']);

    Queue::assertNotPushed(ApplyTrustedSourcesJob::class);
})->with([
    'open to the world' => '0.0.0.0/0',
    'ipv6' => '2001:db8::1',
    'hostname' => 'office.example.com',
]);

test('a trusted source can be removed', function () {
    Queue::fake([ApplyTrustedSourcesJob::class]);
    $database = Database::factory()->create();
    $source = $database->trustedSources()->create(['cidr' => '203.0.113.7/32']);

    $this->actingAs(User::factory()->create())
        ->delete(route('databases.trusted-sources.destroy', [$database->project_id, $database, $source]))
        ->assertSessionHasNoErrors();

    $this->assertModelMissing($source);
    Queue::assertPushed(ApplyTrustedSourcesJob::class);
});

test('a read-only node keeps its own trusted sources', function () {
    Queue::fake([ApplyNodeTrustedSourcesJob::class]);
    $readOnly = DatabaseNode::factory()->readOnly()->create(['name' => 'orders-reports']);

    $this->actingAs(User::factory()->create())
        ->post(route('databases.nodes.trusted-sources.store', [$readOnly->database->project_id, $readOnly->database, $readOnly]), ['cidr' => '198.51.100.0/24'])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('trusted_sources', ['database_id' => $readOnly->database_id, 'database_node_id' => $readOnly->id, 'cidr' => '198.51.100.0/24']);
    expect($readOnly->database->trustedSources()->exists())->toBeFalse()
        ->and($readOnly->refresh()->status)->toBe(DatabaseStatus::Updating);
    Queue::assertPushed(ApplyNodeTrustedSourcesJob::class);
});

test('a node trusted source cannot be removed through the database', function () {
    Queue::fake([ApplyTrustedSourcesJob::class]);
    $readOnly = DatabaseNode::factory()->readOnly()->create(['name' => 'orders-reports']);
    $source = $readOnly->trustedSources()->create(['database_id' => $readOnly->database_id, 'cidr' => '198.51.100.0/24']);

    $this->actingAs(User::factory()->create())
        ->delete(route('databases.trusted-sources.destroy', [$readOnly->database->project_id, $readOnly->database, $source]))
        ->assertNotFound();

    $this->assertModelExists($source);
});
