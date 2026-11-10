<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Jobs\Databases\AdvanceDatabaseFlow;
use App\Models\StorageProvider;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function storageInput(array $overrides = []): array
{
    return [
        'name' => 'R2 archives',
        'endpoint' => 'https://abc123.r2.cloudflarestorage.com/',
        'region' => 'auto',
        'bucket' => 'db-archives',
        'path_prefix' => '/databasify/',
        'path_style' => '1',
        'access_key_id' => 'R2KEY',
        'secret_access_key' => 'abc/def+ghi=',
        ...$overrides,
    ];
}

it('adds storage after writing, reading and deleting a test file', function () {
    fakeBucket();

    $this->post(projectUrl('/storage-providers'), storageInput())->assertRedirect(projectUrl('/storage-providers'))->assertSessionHasNoErrors();

    $provider = StorageProvider::query()->sole();
    expect($provider)
        ->endpoint->toBe('https://abc123.r2.cloudflarestorage.com')
        ->bucket->toBe('db-archives')
        ->path_prefix->toBe('databasify')
        ->path_style->toBeTrue()
        ->credentials->toBe(['access_key_id' => 'R2KEY', 'secret_access_key' => 'abc/def+ghi='])
        ->verified_at->not->toBeNull()
        ->and($provider->getRawOriginal('credentials'))->not->toContain('R2KEY');
    Http::assertSentInOrder([
        fn (Request $request): bool => $request->method() === 'PUT' && str_starts_with($request->url(), 'https://abc123.r2.cloudflarestorage.com/db-archives/databasify/.databasify-check-') && $request->body() === 'databasify',
        fn (Request $request): bool => $request->method() === 'GET' && str_starts_with($request->url(), 'https://abc123.r2.cloudflarestorage.com/db-archives/databasify/.databasify-check-'),
        fn (Request $request): bool => $request->method() === 'DELETE' && str_starts_with($request->url(), 'https://abc123.r2.cloudflarestorage.com/db-archives/databasify/.databasify-check-'),
    ]);
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->header('Authorization')[0] ?? '', 'AWS4-HMAC-SHA256 Credential=R2KEY/') && str_contains($request->header('Authorization')[0], '/auto/s3/aws4_request'));
});

it('addresses the bucket as a subdomain unless path-style is on', function () {
    fakeBucket();

    $this->post(projectUrl('/storage-providers'), storageInput(['path_style' => '0', 'path_prefix' => '', 'endpoint' => 'https://s3.us-east-1.amazonaws.com', 'region' => 'us-east-1']))->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && str_starts_with($request->url(), 'https://db-archives.s3.us-east-1.amazonaws.com/.databasify-check-'));
});

it('shows the storage answer and stores nothing when the keys are refused', function () {
    Http::fake(['*' => Http::response('<Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error>', 403)]);

    $this->post(projectUrl('/storage-providers'), storageInput())
        ->assertSessionHasErrors(['storage' => 'abc123.r2.cloudflarestorage.com answered 403. AccessDenied: Access Denied']);

    expect(StorageProvider::query()->count())->toBe(0);
});

it('refuses storage that does not hand back what was written', function () {
    fakeBucket(readBack: '<html>login</html>');

    $this->post(projectUrl('/storage-providers'), storageInput())->assertSessionHasErrors(['storage' => 'Wrote a test file to db-archives but read back something else. Check the endpoint and addressing style.']);

    expect(StorageProvider::query()->count())->toBe(0);
});

it('says so when the endpoint cannot be reached', function () {
    Http::fake(['*' => Http::failedConnection()]);

    $this->post(projectUrl('/storage-providers'), storageInput())->assertSessionHasErrors(['storage' => 'abc123.r2.cloudflarestorage.com could not be reached.']);
});

it('validates the storage fields without calling the bucket', function (array $input, string $field) {
    Http::fake();
    StorageProvider::factory()->create(['name' => 'taken']);

    $this->post(projectUrl('/storage-providers'), storageInput($input))->assertSessionHasErrors($field);

    Http::assertNothingSent();
})->with([
    'missing name' => [['name' => ''], 'name'],
    'taken name' => [['name' => 'taken'], 'name'],
    'plain http endpoint' => [['endpoint' => 'http://minio.local:9000'], 'endpoint'],
    'quote in endpoint' => [['endpoint' => "https://minio.local/a'\$(id)'"], 'endpoint'],
    'spaced region' => [['region' => 'EU West'], 'region'],
    'bad bucket' => [['bucket' => 'bad bucket'], 'bucket'],
    'prefix with spaces' => [['path_prefix' => 'my backups'], 'path_prefix'],
    'prefix climbing up' => [['path_prefix' => 'a//b'], 'path_prefix'],
    'shell in key' => [['access_key_id' => 'key;rm'], 'access_key_id'],
    'quote in secret' => [['secret_access_key' => "se'cret"], 'secret_access_key'],
    'missing secret' => [['secret_access_key' => ''], 'secret_access_key'],
]);

it('tests the connection of existing storage again', function () {
    $provider = StorageProvider::factory()->create(['verified_at' => null]);
    fakeBucket();

    $this->post(projectUrl("/storage-providers/{$provider->id}/test"))->assertRedirect()->assertSessionHasNoErrors();

    expect($provider->fresh()?->verified_at)->not->toBeNull();
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://s3.example.com/archives/.databasify-check-'));
});

it('keeps the last successful test when a new one fails', function () {
    $provider = StorageProvider::factory()->create(['verified_at' => now()->subDay()]);
    Http::fake(['*' => Http::response('<Error><Code>InvalidAccessKeyId</Code></Error>', 403)]);

    $this->post(projectUrl("/storage-providers/{$provider->id}/test"))->assertSessionHasErrors(['storage' => 's3.example.com answered 403. InvalidAccessKeyId']);

    expect($provider->fresh()?->verified_at?->isSameDay(now()->subDay()))->toBeTrue();
});

it('edits storage and keeps the secret when it is left empty', function () {
    $provider = StorageProvider::factory()->create(['name' => 'old']);
    fakeBucket();

    $this->put(projectUrl("/storage-providers/{$provider->id}"), storageInput(['name' => 'old', 'secret_access_key' => '']))->assertRedirect(projectUrl('/storage-providers'))->assertSessionHasNoErrors();

    expect($provider->fresh())
        ->bucket->toBe('db-archives')
        ->credentials->toBe(['access_key_id' => 'R2KEY', 'secret_access_key' => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY']);
});

it('refuses a name another storage already has', function () {
    $provider = StorageProvider::factory()->create(['name' => 'old']);
    StorageProvider::factory()->create(['name' => 'taken']);
    Http::fake();

    $this->put(projectUrl("/storage-providers/{$provider->id}"), storageInput(['name' => 'taken']))->assertSessionHasErrors('name');
});

it('keeps the bucket of storage a database archives to', function () {
    $database = withRecovery(idleDatabase('postgres', 1, DatabaseMode::Single, 'orders'));
    $provider = $database->pointInTimeRecovery?->storageProvider;
    Http::fake();

    $this->put(projectUrl("/storage-providers/{$provider?->id}"), storageInput(['name' => $provider?->name]))
        ->assertSessionHasErrors(['storage' => "orders archives to {$provider?->name}, so its endpoint, bucket and path stay as they are. Only the name and keys can change."]);

    Http::assertNothingSent();
});

it('sends rotated keys to every database archiving to the storage', function () {
    $database = withRecovery(idleDatabase('mysql', 1, DatabaseMode::Single, 'orders'));
    Queue::fake([AdvanceDatabaseFlow::class]);
    $provider = $database->pointInTimeRecovery?->storageProvider;
    fakeBucket();

    $this->put(projectUrl("/storage-providers/{$provider?->id}"), [...$provider?->bucket()->location(), 'name' => 'renamed', 'path_style' => '1', 'access_key_id' => 'NEWKEY', 'secret_access_key' => 'newsecret'])
        ->assertSessionHasNoErrors();

    expect($provider?->fresh()?->credentials)->toBe(['access_key_id' => 'NEWKEY', 'secret_access_key' => 'newsecret'])
        ->and($database->fresh()->operation)->toBe('Update archive credentials');
    Queue::assertPushed(AdvanceDatabaseFlow::class, fn (AdvanceDatabaseFlow $job): bool => $job->flow->database->is($database));
});

it('renames storage in use without touching its databases', function () {
    $database = withRecovery(idleDatabase('mysql', 1, DatabaseMode::Single, 'orders'));
    Queue::fake([AdvanceDatabaseFlow::class]);
    $provider = $database->pointInTimeRecovery?->storageProvider;
    fakeBucket();

    $this->put(projectUrl("/storage-providers/{$provider?->id}"), [...$provider?->bucket()->location(), 'name' => 'renamed', 'path_style' => '1', 'access_key_id' => 'AKIDEXAMPLE'])
        ->assertSessionHasNoErrors();

    expect($provider?->fresh()?->name)->toBe('renamed');
    Queue::assertNotPushed(AdvanceDatabaseFlow::class);
});

it('refuses to remove storage while a database archives to it', function () {
    $database = withRecovery(idleDatabase('postgres', 1, DatabaseMode::Single, 'orders'));
    $provider = $database->pointInTimeRecovery?->storageProvider;

    $this->delete(projectUrl("/storage-providers/{$provider?->id}"))->assertSessionHasErrors(['provider' => "orders archives to {$provider?->name}, turn off its point-in-time recovery first."]);
    $database->pointInTimeRecovery?->delete();
    $this->delete(projectUrl("/storage-providers/{$provider?->id}"))->assertSessionHasNoErrors();

    expect(StorageProvider::query()->count())->toBe(0);
});

it('lists storage with the databases archiving to it and never sends the secret', function () {
    withRecovery(idleDatabase('postgres', 1, DatabaseMode::Single, 'orders'));

    $this->get(projectUrl('/storage-providers'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('storage/index')
        ->where('providers.0.bucket', 'archives')
        ->where('providers.0.databases.0.name', 'orders')
        ->missing('providers.0.credentials'));

    expect($this->get(projectUrl('/storage-providers'))->getContent())->not->toContain('EXAMPLEKEY');
});

it('shows the add and edit pages without the secret', function () {
    $provider = StorageProvider::factory()->create();

    $this->get(projectUrl('/storage-providers/create'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('storage/create'));
    $this->get(projectUrl("/storage-providers/{$provider->id}/edit"))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('storage/edit')
        ->where('provider.access_key_id', 'AKIDEXAMPLE')
        ->missing('provider.credentials'));

    expect($this->get(projectUrl("/storage-providers/{$provider->id}/edit"))->getContent())->not->toContain('EXAMPLEKEY');
});
