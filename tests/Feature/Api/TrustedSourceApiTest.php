<?php

declare(strict_types=1);

use App\Jobs\Databases\ApplyNodeTrustedSourcesJob;
use App\Jobs\Databases\ApplyTrustedSourcesJob;
use App\Models\ApiKey;
use App\Models\Database;
use App\Models\DatabaseNode;
use Illuminate\Support\Facades\Queue;

test('a single address is trusted as a /32', function () {
    Queue::fake([ApplyTrustedSourcesJob::class]);
    $database = Database::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->postJson("/api/v1/databases/{$database->id}/trusted-sources", ['cidr' => '203.0.113.7', 'label' => 'office'])
        ->assertAccepted()
        ->assertJsonPath('data.status', 'updating');

    $this->getJson("/api/v1/databases/{$database->id}/trusted-sources")
        ->assertOk()
        ->assertJsonPath('data.0.cidr', '203.0.113.7/32')
        ->assertJsonPath('data.0.label', 'office');
});

test('a trusted source must be an ipv4 range', function () {
    $database = Database::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->postJson("/api/v1/databases/{$database->id}/trusted-sources", ['cidr' => '0.0.0.0/0'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cidr');
});

test('a trusted source is removed', function () {
    Queue::fake([ApplyTrustedSourcesJob::class]);
    $database = Database::factory()->create();
    $source = $database->trustedSources()->create(['cidr' => '203.0.113.7/32']);
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->deleteJson("/api/v1/databases/{$database->id}/trusted-sources/{$source->id}")->assertAccepted();

    $this->assertModelMissing($source);
});

test('a node trusted source cannot be removed through the database', function () {
    $readOnly = DatabaseNode::factory()->readOnly()->create(['name' => 'orders-reports']);
    $source = $readOnly->trustedSources()->create(['database_id' => $readOnly->database_id, 'cidr' => '198.51.100.0/24']);
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->deleteJson("/api/v1/databases/{$readOnly->database_id}/trusted-sources/{$source->id}")->assertNotFound();
});

test('a read-only node keeps its own trusted sources', function () {
    Queue::fake([ApplyNodeTrustedSourcesJob::class]);
    $readOnly = DatabaseNode::factory()->readOnly()->create(['name' => 'orders-reports']);
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->postJson("/api/v1/databases/{$readOnly->database_id}/nodes/{$readOnly->id}/trusted-sources", ['cidr' => '198.51.100.0/24'])
        ->assertAccepted()
        ->assertJsonPath('data.status', 'updating');

    $this->getJson("/api/v1/databases/{$readOnly->database_id}/nodes/{$readOnly->id}/trusted-sources")
        ->assertOk()
        ->assertJsonPath('data.0.cidr', '198.51.100.0/24');
});
