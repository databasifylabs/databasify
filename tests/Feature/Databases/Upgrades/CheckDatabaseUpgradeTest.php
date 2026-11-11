<?php

declare(strict_types=1);

use App\Actions\Databases\Upgrades\CheckDatabaseUpgrade;
use App\DTOs\Databases\EngineImage;
use App\Enums\Databases\DatabaseMode;
use App\Events\DatabaseAlertRaised;
use App\Exceptions\Images\ImageUnavailable;
use App\Models\Database;
use App\Models\User;
use Illuminate\Support\Facades\Event;

it('offers a newer release of the line without asking the servers', function () {
    withImage('postgres', '18.6');
    $database = idleDatabase('postgres', 2);
    withImage('postgres', '18.7', 'sha256:'.str_repeat('7', 64));
    $ssh = fakeSsh();

    $offered = resolve(CheckDatabaseUpgrade::class)->handle($database);

    expect($offered)->toEqual(new EngineImage('ghcr.io/databasifylabs/databasify-postgres', 'sha256:'.str_repeat('7', 64), '18.7', '0.2.0'))
        ->and($database->fresh()->availableImage())->toEqual($offered)
        ->and($database->fresh()->availableUpdateRefreshesImage())->toBeFalse()
        ->and($ssh->runs)->toBeEmpty();
});

it('only ever offers a release of the database\'s own line', function (string $engine, string $line, string $running, string $offered) {
    withImage($engine, $running);
    $database = idleDatabase($engine, 1, DatabaseMode::Single, versionLine: $line);
    foreach (['7.2.18', '7.4.0', '7.4.6', '8.10.3', '8.12.0', '9.0.0', '9.1.4', '10.11.20', '11.4.14', '12.3.4'] as $release) {
        withImage($engine, $release);
    }

    expect(resolve(CheckDatabaseUpgrade::class)->handle($database)?->release)->toBe($offered);
})->with([
    'redis 7.2 never moves to 7.4' => ['redis', '7.2', '7.2.17', '7.2.18'],
    'redis 8 takes a newer minor' => ['redis', '8', '8.10.3', '8.12.0'],
    'valkey 9 stays on 9' => ['valkey', '9', '9.1.3', '9.1.4'],
    'mariadb 10.11 compares as numbers' => ['mariadb', '10.11', '10.11.19', '10.11.20'],
]);

it('offers a refresh when the release was rebuilt under the same tag', function () {
    withImage('postgres', '18.6', 'sha256:'.str_repeat('a', 64));
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    withImage('postgres', '18.6', 'sha256:'.str_repeat('b', 64));

    expect(resolve(CheckDatabaseUpgrade::class)->handle($database)?->digest)->toBe('sha256:'.str_repeat('b', 64))
        ->and($database->fresh()->available_image?->release)->toBe('18.6')
        ->and($database->fresh()->availableUpdateRefreshesImage())->toBeTrue();
});

it('offers nothing and clears an old offer while the database runs the newest image', function () {
    withImage('postgres', '18.6');
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    $database->update(['available_image' => new EngineImage('ghcr.io/databasifylabs/databasify-postgres', 'sha256:'.str_repeat('9', 64), '18.9', '0.2.0')]);

    expect(resolve(CheckDatabaseUpgrade::class)->handle($database))->toBeNull()
        ->and($database->fresh()->availableImage())->toBeNull();
});

it('never offers an older release than the one the database runs', function () {
    withImage('postgres', '18.6');
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    $database->update(['image' => new EngineImage('ghcr.io/databasifylabs/databasify-postgres', 'sha256:'.str_repeat('9', 64), '18.9', '0.2.0')]);

    expect(resolve(CheckDatabaseUpgrade::class)->handle($database))->toBeNull();
});

it('keeps the current offer when the registry cannot be reached', function () {
    withImage('postgres', '18.6');
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    withImage('postgres', '18.7');
    resolve(CheckDatabaseUpgrade::class)->handle($database);
    imageRegistry()->goDown();

    expect(fn () => resolve(CheckDatabaseUpgrade::class)->handle($database))->toThrow(ImageUnavailable::class)
        ->and($database->fresh()->available_image?->release)->toBe('18.7');
});

it('keeps the current image when the newest one is outside the range', function () {
    Event::fake([DatabaseAlertRaised::class]);
    withImage('valkey', '9.1.3');
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    withImage('valkey', '9.1.4', sdk: '0.3.0');

    expect(resolve(CheckDatabaseUpgrade::class)->handle($database))->toBeNull()
        ->and($database->fresh()->availableImage())->toBeNull()
        ->and($database->fresh()->image->release)->toBe('9.1.3');
    Event::assertDispatched(DatabaseAlertRaised::class, fn (DatabaseAlertRaised $alert): bool => $alert->database->is($database)
        && $alert->subject === 'main stays on Valkey 9.1.3'
        && $alert->message === 'Valkey 9.1.4 is built on SDK 0.3.0; this Databasify supports ^0.2.');
});

it('offers the newest supported release and warns about a newer refused one', function () {
    Event::fake([DatabaseAlertRaised::class]);
    withImage('valkey', '9.1.2');
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    withImage('valkey', '9.1.3');
    withImage('valkey', '9.1.4', sdk: '0.3.0');

    expect(resolve(CheckDatabaseUpgrade::class)->handle($database)?->release)->toBe('9.1.3')
        ->and($database->fresh()->availableImage()?->release)->toBe('9.1.3');
    Event::assertDispatched(DatabaseAlertRaised::class, fn (DatabaseAlertRaised $alert): bool => $alert->message === 'Valkey 9.1.4 is built on SDK 0.3.0; this Databasify supports ^0.2.');
});

it('raises the refusal alert once per refused image', function () {
    Event::fake([DatabaseAlertRaised::class]);
    withImage('valkey', '9.1.3');
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    withImage('valkey', '9.1.4', sdk: '0.3.0');

    resolve(CheckDatabaseUpgrade::class)->handle($database);
    resolve(CheckDatabaseUpgrade::class)->handle($database->fresh());
    Event::assertDispatchedTimes(DatabaseAlertRaised::class, 1);

    withImage('valkey', '9.1.5', sdk: '0.3.0');
    resolve(CheckDatabaseUpgrade::class)->handle($database->fresh());
    Event::assertDispatchedTimes(DatabaseAlertRaised::class, 2);
});

it('alerts again for a refused image after a supported one was offered', function () {
    Event::fake([DatabaseAlertRaised::class]);
    withImage('valkey', '9.1.3');
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    withImage('valkey', '9.1.4', 'sha256:'.str_repeat('4', 64), sdk: '0.3.0');
    resolve(CheckDatabaseUpgrade::class)->handle($database);

    withImage('valkey', '9.1.4', 'sha256:'.str_repeat('5', 64));
    expect(resolve(CheckDatabaseUpgrade::class)->handle($database->fresh())?->release)->toBe('9.1.4');

    withImage('valkey', '9.1.4', 'sha256:'.str_repeat('4', 64), sdk: '0.3.0');
    resolve(CheckDatabaseUpgrade::class)->handle($database->fresh());
    Event::assertDispatchedTimes(DatabaseAlertRaised::class, 2);
});

it('checks for an upgrade over http', function () {
    $this->actingAs(User::factory()->create());
    withImage('valkey', '9.1.1');
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    withImage('valkey', '9.1.2');

    $this->post(projectUrl("/databases/{$database->id}/upgrade-check"))->assertRedirect()->assertSessionHasNoErrors();

    expect($database->fresh()->available_image?->release)->toBe('9.1.2');
});

it('checks every database for upgrades once a day', function () {
    idleDatabase('valkey', 1, DatabaseMode::Single, 'first');
    newDatabase('redis', 1, DatabaseMode::Single, 'second');
    withImage('valkey', '9.1.2');
    withImage('redis', '8.2.3');

    $this->artisan('databases:check-upgrades')->assertSuccessful();

    expect(Database::query()->get()->mapWithKeys(fn (Database $database): array => [$database->name => $database->available_image?->release])->all())->toBe(['first' => '9.1.2', 'second' => '8.2.3']);
});
