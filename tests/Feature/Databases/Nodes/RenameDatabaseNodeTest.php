<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Jobs\Databases\AdvanceDatabaseFlow;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('renames a read-only node and refuses a name another node uses', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $reader = withReadOnlyNode($database, ['name' => 'main-ro-1']);
    withReadOnlyNode($database, ['name' => 'main-ro-2']);

    $this->put(projectUrl("/databases/{$database->id}/nodes/{$reader->id}"), ['name' => 'main-ro-2'])->assertSessionHasErrors('name');
    $this->put(projectUrl("/databases/{$database->id}/nodes/{$reader->id}"), ['name' => 'Reports'])->assertSessionHasErrors('name');
    $this->put(projectUrl("/databases/{$database->id}/nodes/{$reader->id}"), ['name' => 'reports'])->assertSessionHasNoErrors();

    expect($reader->fresh()->name)->toBe('reports');
});

it('moves a renamed read-only node to its new names and gives it a new certificate', function () {
    fakeCloudflare();
    $database = withZone(idleDatabase('valkey', 1, DatabaseMode::Single));
    $reader = withReadOnlyNode($database, ['name' => 'reports']);
    Queue::fake([AdvanceDatabaseFlow::class]);
    $database->update(['settings' => $database->settings->withNodeCertificates($reader->id, ['server.pem' => 'pem'])]);

    $this->put(projectUrl("/databases/{$database->id}/nodes/{$reader->id}"), ['name' => 'analytics'])->assertSessionHasNoErrors();

    $looked = collect(Http::recorded(fn (Request $request): bool => $request->method() === 'GET'))->map(fn (array $pair): string => $pair[0]['name'])->unique()->sort()->values()->all();
    expect($reader->fresh()->name)->toBe('analytics')
        ->and($looked)->toBe(['analytics.db.example.com', 'private-analytics.db.example.com', 'private-reports.db.example.com', 'reports.db.example.com'])
        ->and($database->fresh()->settings->nodeCertificate($reader->id, 'server.pem'))->toBeNull()
        ->and($database->fresh()->operation)->toBe('Redeploy analytics');
    Queue::assertPushed(AdvanceDatabaseFlow::class);
});

it('refuses a new name whose hostnames hold records databasify did not create', function () {
    fakeCloudflare([['id' => 'theirs', 'content' => '192.0.2.1']]);
    $database = withZone(idleDatabase('valkey', 1, DatabaseMode::Single));
    $reader = withReadOnlyNode($database, ['name' => 'reports']);
    Queue::fake([AdvanceDatabaseFlow::class]);

    $this->put(projectUrl("/databases/{$database->id}/nodes/{$reader->id}"), ['name' => 'analytics'])->assertSessionHasErrors('name');

    expect($reader->fresh()->name)->toBe('reports');
    Queue::assertNothingPushed();
});
