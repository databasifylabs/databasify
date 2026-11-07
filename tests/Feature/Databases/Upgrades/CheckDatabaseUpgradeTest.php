<?php

declare(strict_types=1);

use App\Actions\Databases\Upgrades\CheckDatabaseUpgrade;
use App\DTOs\Databases\EngineImage;
use App\Enums\Databases\DatabaseMode;
use App\Exceptions\Images\ImageUnavailable;
use App\Models\Database;
use App\Models\User;

it('offers a newer release of the line without asking the servers', function () {
    withImage('postgres', '18.6');
    $database = idleDatabase('postgres', 2);
    withImage('postgres', '18.7', 'sha256:'.str_repeat('7', 64));
    $ssh = fakeSsh();

    $offered = resolve(CheckDatabaseUpgrade::class)->handle($database);

    expect($offered)->toEqual(new EngineImage('ghcr.io/databasifylabs/databasify-postgres', 'sha256:'.str_repeat('7', 64), '18.7'))
        ->and($database->fresh()->availableImage())->toEqual($offered)
        ->and($database->fresh()->availableUpdateRefreshesImage())->toBeFalse()
        ->and($ssh->runs)->toBeEmpty();
});

it('offers a refresh when the release was rebuilt under the same tag', function () {
    withImage('postgres', '18.6', 'sha256:'.str_repeat('a', 64));
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    withImage('postgres', '18.6', 'sha256:'.str_repeat('b', 64));

    expect(resolve(CheckDatabaseUpgrade::class)->handle($database)?->digest)->toBe('sha256:'.str_repeat('b', 64))
        ->and($database->fresh()->available_image_release)->toBe('18.6')
        ->and($database->fresh()->availableUpdateRefreshesImage())->toBeTrue();
});

it('offers nothing and clears an old offer while the database runs the newest image', function () {
    withImage('postgres', '18.6');
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    $database->update((new EngineImage('ghcr.io/databasifylabs/databasify-postgres', 'sha256:'.str_repeat('9', 64), '18.9'))->toAvailableRow());

    expect(resolve(CheckDatabaseUpgrade::class)->handle($database))->toBeNull()
        ->and($database->fresh()->availableImage())->toBeNull();
});

it('never offers an older release than the one the database runs', function () {
    withImage('postgres', '18.6');
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    $database->update((new EngineImage('ghcr.io/databasifylabs/databasify-postgres', 'sha256:'.str_repeat('9', 64), '18.9'))->toRow());

    expect(resolve(CheckDatabaseUpgrade::class)->handle($database))->toBeNull();
});

it('keeps the current offer when the registry cannot be reached', function () {
    withImage('postgres', '18.6');
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    withImage('postgres', '18.7');
    resolve(CheckDatabaseUpgrade::class)->handle($database);
    imageRegistry()->goDown();

    expect(fn () => resolve(CheckDatabaseUpgrade::class)->handle($database))->toThrow(ImageUnavailable::class)
        ->and($database->fresh()->available_image_release)->toBe('18.7');
});

it('checks for an upgrade over http', function () {
    $this->actingAs(User::factory()->create());
    withImage('valkey', '9.1.1');
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    withImage('valkey', '9.1.2');

    $this->post(projectUrl("/databases/{$database->id}/upgrade-check"))->assertRedirect()->assertSessionHasNoErrors();

    expect($database->fresh()->available_image_release)->toBe('9.1.2');
});

it('checks every database for upgrades once a day', function () {
    idleDatabase('valkey', 1, DatabaseMode::Single, 'first');
    newDatabase('redis', 1, DatabaseMode::Single, 'second');
    withImage('valkey', '9.1.2');
    withImage('redis', '8.2.3');

    $this->artisan('databases:check-upgrades')->assertSuccessful();

    expect(Database::query()->pluck('available_image_release', 'name')->all())->toBe(['first' => '9.1.2', 'second' => '8.2.3']);
});
