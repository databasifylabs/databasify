<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config([
        'databasify.updates.path' => storage_path('framework/testing/updater-'.Str::random(8)),
        'databasify.updates.source' => 'https://get.databasify.io/version.json',
        'databasify.updates.pinned' => null,
    ]);
});

afterEach(function () {
    File::deleteDirectory(config('databasify.updates.path'));
});

function installUpdater(): string
{
    $path = config('databasify.updates.path');
    File::ensureDirectoryExists("{$path}/request");
    File::put("{$path}/installed", '');

    return $path;
}

function rememberRelease(string $version, string $notes = 'Fixes'): void
{
    Setting::query()->create(['name' => 'latest_release', 'value' => json_encode([
        'version' => $version,
        'published_at' => '2026-11-01T00:00:00Z',
        'url' => "https://github.com/databasifylabs/databasify/releases/tag/v{$version}",
        'notes' => $notes,
    ])]);
}

test('updates are not available without the host updater', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('updates.index'))
        ->assertNotFound();
});

test('updates page is displayed', function () {
    installUpdater();

    $this->actingAs(User::factory()->create())
        ->get(route('updates.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('updates/index'));
});

test('release notes reach the page as markdown', function () {
    installUpdater();
    rememberRelease('9.0.0', "> [!WARNING]\n> Back up first.");

    $this->actingAs(User::factory()->create())
        ->get(route('updates.index'))
        ->assertInertia(fn (Assert $page) => $page->where('release.notes', "> [!WARNING]\n> Back up first."));
});

test('checking for a release remembers the latest version', function () {
    installUpdater();
    Http::fake(['https://get.databasify.io/version.json' => Http::response([
        'version' => '0.3.0',
        'published_at' => '2026-11-10T00:00:00Z',
        'url' => 'https://github.com/databasifylabs/databasify/releases/tag/v0.3.0',
        'notes' => 'New engines',
    ])]);

    $this->actingAs(User::factory()->create())
        ->post(route('updates.check'))
        ->assertSessionHasNoErrors();

    expect(json_decode(Setting::query()->where('name', 'latest_release')->sole()->value, true)['version'])->toBe('0.3.0');
});

test('an unreachable release feed is reported', function () {
    installUpdater();
    Http::fake(['https://get.databasify.io/version.json' => Http::response(status: 503)]);

    $this->actingAs(User::factory()->create())
        ->post(route('updates.check'))
        ->assertSessionHasErrors(['check' => 'Could not reach get.databasify.io to check for a new release. Try again later.']);

    expect(Setting::query()->exists())->toBeFalse();
});

test('an update is requested when a newer release exists', function () {
    $path = installUpdater();
    rememberRelease('9.0.0');

    $this->actingAs(User::factory()->create())
        ->post(route('updates.store'))
        ->assertSessionHasNoErrors();

    expect(is_file("{$path}/request/update"))->toBeTrue();
});

test('an update is refused', function (Closure $arrange, string $reason) {
    $path = installUpdater();
    $arrange($path);

    $this->actingAs(User::factory()->create())
        ->post(route('updates.store'))
        ->assertSessionHasErrors(['update' => $reason]);
})->with([
    'already up to date' => [fn () => rememberRelease('0.2.0'), 'Databasify is already up to date.'],
    'pinned version' => [function () {
        rememberRelease('9.0.0');
        config(['databasify.updates.pinned' => '0.2.0']);
    }, 'This install is pinned to 0.2.0 in /opt/databasify/.env.'],
    'already running' => [function (string $path) {
        rememberRelease('9.0.0');
        File::put("{$path}/request/update", 'now');
    }, 'An update is already running.'],
]);

test('the update log can be downloaded', function () {
    $path = installUpdater();
    File::put("{$path}/update.log", "pulling image\n");

    $response = $this->actingAs(User::factory()->create())
        ->get(route('updates.log'))
        ->assertOk()
        ->assertDownload('databasify-update.log');

    expect($response->streamedContent())->toBe("pulling image\n");
});

test('the daily release check skips installs without the updater', function () {
    $this->artisan('updates:check')
        ->expectsOutputToContain('Updates run only on Docker installs with the updater.')
        ->assertSuccessful();

    Http::assertNothingSent();
});
