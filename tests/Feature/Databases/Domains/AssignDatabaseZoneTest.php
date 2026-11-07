<?php

declare(strict_types=1);

use App\Actions\Databases\Domains\AssignDatabaseZone;
use App\Enums\Databases\DatabaseMode;
use App\Jobs\Databases\UpgradeDatabaseJob;
use App\Models\DnsZone;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('serves the database under a picked domain after checking its names are free', function () {
    fakeCloudflare([]);
    $database = idleDatabase('mysql', 1, DatabaseMode::Single, 'app-db');
    withReadOnlyNode($database, ['name' => 'reports']);
    Queue::fake([UpgradeDatabaseJob::class]);
    $zone = DnsZone::factory()->create(['name' => 'example.com']);

    $this->put(projectUrl("/databases/{$database->id}/domain"), ['dns_zone_id' => $zone->id])->assertRedirect()->assertSessionHasNoErrors();

    $checked = collect(Http::recorded())->map(fn (array $pair): string => $pair[0]['name'])->sort()->values()->all();
    expect($database->fresh()->dns_zone_id)->toBe($zone->id)
        ->and($database->fresh()->operation)->toBe('Serve on app-db.db.example.com')
        ->and($checked)->toBe(['app-db.db.example.com', 'private-app-db.db.example.com', 'private-replica-app-db.db.example.com', 'private-reports.db.example.com', 'replica-app-db.db.example.com', 'reports.db.example.com']);
    Queue::assertPushed(UpgradeDatabaseJob::class, fn (UpgradeDatabaseJob $job): bool => $job->database->is($database) && ! $job->installUpdate);
});

it('refuses a name that has records databasify did not create', function () {
    fakeCloudflare([['id' => 'theirs', 'content' => '192.0.2.1']]);
    $database = idleDatabase('mysql', 1, DatabaseMode::Single, 'app-db');
    Queue::fake([UpgradeDatabaseJob::class]);
    $zone = DnsZone::factory()->create(['name' => 'example.com']);

    $this->put(projectUrl("/databases/{$database->id}/domain"), ['dns_zone_id' => $zone->id])
        ->assertSessionHasErrors(['dns_zone_id' => "app-db.db.example.com already has DNS records Databasify didn't create. Remove them or pick another name."]);

    expect($database->fresh()->dns_zone_id)->toBeNull();
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    Queue::assertNothingPushed();
});

it('reuses names that only hold records databasify created', function () {
    fakeCloudflare();
    $database = idleDatabase('valkey', 1, DatabaseMode::Single, 'cache');
    Queue::fake([UpgradeDatabaseJob::class]);

    $this->put(projectUrl("/databases/{$database->id}/domain"), ['dns_zone_id' => DnsZone::factory()->create(['name' => 'example.com'])->id])->assertSessionHasNoErrors();

    Queue::assertPushed(UpgradeDatabaseJob::class);
});

it('clears the old names when it moves to another domain or stops using one', function () {
    fakeCloudflare();
    $database = withZone(idleDatabase('valkey', 1, DatabaseMode::Single, 'cache'));
    $other = DnsZone::factory()->create(['name' => 'example.org', 'external_id' => 'zone-1']);
    Queue::fake([UpgradeDatabaseJob::class]);

    resolve(AssignDatabaseZone::class)->handle($database, $other);
    $database->finishOperation();
    resolve(AssignDatabaseZone::class)->handle($database->fresh() ?? $database, null);

    $looked = collect(Http::recorded(fn (Request $request): bool => $request->method() === 'GET'))->map(fn (array $pair): string => $pair[0]['name']);
    expect($looked)->toContain('cache.db.example.com', 'replica-cache.db.example.com', 'cache.db.example.org')
        ->and($database->fresh()->dns_zone_id)->toBeNull()
        ->and($database->fresh()->operation)->toBe('Stop using a domain');
});

it('refuses to pick the domain it already uses, or none when it has none', function () {
    $database = withZone(idleDatabase('valkey', 1, DatabaseMode::Single, 'cache'));
    $plain = idleDatabase('valkey', 1, DatabaseMode::Single, 'plain');
    Queue::fake([UpgradeDatabaseJob::class]);

    $this->put(projectUrl("/databases/{$database->id}/domain"), ['dns_zone_id' => $database->dns_zone_id])->assertSessionHasErrors(['dns_zone_id' => 'cache already uses example.com.']);
    $this->put(projectUrl("/databases/{$plain->id}/domain"), ['dns_zone_id' => null])->assertSessionHasErrors(['dns_zone_id' => "plain doesn't use a domain."]);
    $this->put(projectUrl("/databases/{$plain->id}/domain"), ['dns_zone_id' => 999])->assertSessionHasErrors('dns_zone_id');

    Queue::assertNothingPushed();
});

it('keeps the domain while the database is busy', function () {
    Http::fake();
    $database = newDatabase('valkey', 1, DatabaseMode::Single);
    Queue::fake([UpgradeDatabaseJob::class]);

    expect(fn () => resolve(AssignDatabaseZone::class)->handle($database, DnsZone::factory()->create()))->toThrow(ValidationException::class, 'busy with Create Valkey database')
        ->and($database->fresh()->dns_zone_id)->toBeNull();
    Http::assertNothingSent();
});
