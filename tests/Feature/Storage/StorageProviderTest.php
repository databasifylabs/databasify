<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\ApplySettingsJob;
use App\Models\Database;
use App\Models\PointInTimeRecovery;
use App\Models\Project;
use App\Models\StorageProvider;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

function fakeBucket(string $readsBack = 'databasify'): void
{
    Http::fake(['https://s3.example.com/archives/*' => fn (Request $request) => match ($request->method()) {
        'GET' => Http::response($readsBack),
        'DELETE' => Http::response(status: 204),
        default => Http::response(),
    }]);
}

function bucketForm(array $overrides = []): array
{
    return [
        'name' => 'backups',
        'endpoint' => 'https://s3.example.com',
        'region' => 'us-east-1',
        'bucket' => 'archives',
        'path_style' => true,
        'access_key_id' => 'AKIDEXAMPLE',
        'secret_access_key' => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
        ...$overrides,
    ];
}

test('storage page is displayed', function () {
    $provider = StorageProvider::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('storage-providers.index', $provider->project_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('storage/index')->has('providers', 1));
});

test('a storage provider is added after a test file round trip', function () {
    fakeBucket();
    $project = Project::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('storage-providers.store', $project), bucketForm())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('storage-providers.index', $project));

    expect(StorageProvider::query()->sole())
        ->bucket->toBe('archives')
        ->verified_at->not->toBeNull();
    Http::assertSentCount(3);
});

test('a bucket that returns something else is refused', function () {
    fakeBucket(readsBack: '<html>proxy</html>');
    $project = Project::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('storage-providers.store', $project), bucketForm())
        ->assertSessionHasErrors(['storage' => 'Wrote a test file to archives but read back something else. Check the endpoint and addressing style.']);

    expect(StorageProvider::query()->exists())->toBeFalse();
});

test('storage endpoints must be https urls without shell characters', function (string $endpoint) {
    $project = Project::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('storage-providers.store', $project), bucketForm(['endpoint' => $endpoint]))
        ->assertSessionHasErrors('endpoint');

    Http::assertNothingSent();
})->with([
    'plain http' => 'http://s3.example.com',
    'command substitution' => 'https://s3.example.com/$(id)',
]);

test('storage provider edit page is displayed', function () {
    $provider = StorageProvider::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('storage-providers.edit', [$provider->project_id, $provider]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('storage/edit')->where('provider.id', $provider->id));
});

test('a storage provider used for archives keeps its location', function () {
    fakeBucket();
    $provider = StorageProvider::factory()->create(['name' => 'backups']);
    $database = Database::factory()->for($provider->project)->create(['name' => 'orders']);
    PointInTimeRecovery::factory()->for($database)->for($provider)->create();

    $this->actingAs(User::factory()->create())
        ->put(route('storage-providers.update', [$provider->project_id, $provider]), bucketForm(['bucket' => 'elsewhere']))
        ->assertSessionHasErrors(['storage' => 'orders archives to backups, so its endpoint, bucket and path stay as they are. Only the name and keys can change.']);

    expect($provider->refresh()->bucket)->toBe('archives');
});

test('rotating keys of an archive bucket reapplies the settings of its databases', function () {
    fakeBucket();
    Queue::fake([ApplySettingsJob::class]);
    $provider = StorageProvider::factory()->create();
    $database = Database::factory()->for($provider->project)->create();
    PointInTimeRecovery::factory()->for($database)->for($provider)->create();

    $this->actingAs(User::factory()->create())
        ->put(route('storage-providers.update', [$provider->project_id, $provider]), bucketForm(['access_key_id' => 'AKIDROTATED']))
        ->assertSessionHasNoErrors();

    expect($database->refresh()->status)->toBe(DatabaseStatus::Updating);
    Queue::assertPushed(ApplySettingsJob::class, fn (ApplySettingsJob $job) => $job->database->is($database));
});

test('testing a storage provider marks it verified', function () {
    fakeBucket();
    $provider = StorageProvider::factory()->create(['verified_at' => null]);

    $this->actingAs(User::factory()->create())
        ->post(route('storage-providers.test', [$provider->project_id, $provider]))
        ->assertSessionHasNoErrors();

    expect($provider->refresh()->verified_at)->not->toBeNull();
});

test('a storage provider used for archives cannot be deleted', function () {
    $provider = StorageProvider::factory()->create(['name' => 'backups']);
    $database = Database::factory()->for($provider->project)->create(['name' => 'orders']);
    PointInTimeRecovery::factory()->for($database)->for($provider)->create();

    $this->actingAs(User::factory()->create())
        ->delete(route('storage-providers.destroy', [$provider->project_id, $provider]))
        ->assertSessionHasErrors(['provider' => 'orders archives to backups, turn off its point-in-time recovery first.']);

    $this->assertModelExists($provider);
});

test('an unused storage provider can be deleted', function () {
    $provider = StorageProvider::factory()->create();

    $this->actingAs(User::factory()->create())
        ->delete(route('storage-providers.destroy', [$provider->project_id, $provider]))
        ->assertSessionHasNoErrors();

    $this->assertModelMissing($provider);
});
