<?php

declare(strict_types=1);

use App\Actions\Databases\Migrations\RefreshMigration;
use App\Actions\Databases\Migrations\RunMigration;
use App\Actions\Databases\Migrations\StartMigration;
use App\DTOs\Migrations\MigrationSource;
use App\Enums\Migrations\MigrationMode;
use App\Enums\Migrations\MigrationStatus;
use App\Events\DatabaseAlertRaised;
use App\Jobs\Databases\RunMigrationJob;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

function progressReply(string $copy, string $log, ?int $lag = null): array
{
    return ['copy' => $copy, 'log' => $log, 'subscribedTables' => 0, 'readyTables' => 0, 'lagSeconds' => $lag, 'lagUnknown' => $lag === null];
}

function checkReply(bool $reachable, bool $canReplicate, ?string $error = null): array
{
    return ['reachable' => $reachable, 'error' => $error, 'version' => '8.4.3', 'walLevel' => null, 'canReplicate' => $canReplicate, 'sizeMb' => 3, 'databases' => ['shop'], 'unreadable' => [], 'unowned' => [], 'occupied' => [], 'keys' => null, 'warnings' => [], 'problems' => []];
}

function sourceAt(): MigrationSource
{
    return new MigrationSource('db.example.com', 3306, 'migrator', 'secret', databases: ['shop']);
}

it('refuses a source the engine cannot reach or follow', function () {
    $database = migratingDatabase('mysql');
    fakeEngine(fakeSsh()->respondToEngine('migration check', checkReply(false, false, 'Access denied')));

    expect(fn () => resolve(StartMigration::class)->handle($database, sourceAt(), MigrationMode::OneTime))->toThrow(ValidationException::class, 'Access denied');

    fakeEngine(fakeSsh()->respondToEngine('migration check', checkReply(true, false)));

    expect(fn () => resolve(StartMigration::class)->handle($database->fresh(), sourceAt(), MigrationMode::Continuous))->toThrow(ValidationException::class)
        ->and($database->migrations()->count())->toBe(0);
});

it('refuses a source the engine says it cannot copy from', function () {
    $database = migratingDatabase('mysql');
    fakeEngine(fakeSsh()->respondToEngine('migration check', [...checkReply(true, false), 'problems' => ['MySQL Shell copies only as a source user with SELECT on mysql.*']]));

    expect(fn () => resolve(StartMigration::class)->handle($database, sourceAt(), MigrationMode::OneTime))->toThrow(ValidationException::class, 'SELECT on mysql.*')
        ->and($database->migrations()->count())->toBe(0);
});

it('starts a continuous migration on the primary and marks it copying', function () {
    $ssh = fakeEngine();
    $database = migratingDatabase('mysql');
    Queue::fake([RunMigrationJob::class]);

    $migration = resolve(StartMigration::class)->handle($database, sourceAt(), MigrationMode::Continuous);
    resolve(RunMigration::class)->handle($migration);
    $start = collect($ssh->runs)->search(fn (array $run): bool => str_contains($run['script'], '| databasify migration start '));

    expect(json_decode($ssh->engineRequestAt($start), true))->toBe([
        'source' => ['host' => 'db.example.com', 'port' => 3306, 'username' => 'migrator', 'password' => 'secret', 'tls' => true],
        'databases' => ['shop'],
        'continuous' => true,
        'owner' => 'databasify',
        'publication' => null,
    ])
        ->and($migration->fresh()->status)->toBe(MigrationStatus::Copying);
});

it('moves a continuous migration to syncing once the copy succeeded and records the lag', function () {
    $database = migratingDatabase('mysql');
    $migration = migrationOf($database, MigrationMode::Continuous);

    fakeEngine(fakeSsh()->respondToEngine('migration progress', progressReply('running', 'copying shop')));
    resolve(RefreshMigration::class)->handle($migration);
    expect($migration->fresh()->status)->toBe(MigrationStatus::Copying);

    fakeEngine(fakeSsh()->respondToEngine('migration progress', progressReply('succeeded', 'following the source', 4)));
    resolve(RefreshMigration::class)->handle($migration->fresh());
    resolve(RefreshMigration::class)->handle($migration->fresh());

    expect($migration->fresh())->status->toBe(MigrationStatus::Syncing)->lag_seconds->toBe(4);
});

it('completes a one-time copy and records its databases with grants for the default user', function () {
    $database = migratingDatabase('mysql');
    $migration = migrationOf($database, MigrationMode::OneTime);
    $migration->update(['source' => sourceAt()]);
    $ssh = fakeEngine(fakeSsh()->respondToEngine('migration progress', progressReply('succeeded', 'copied')));

    resolve(RefreshMigration::class)->handle($migration->fresh());
    $grant = collect($ssh->runs)->search(fn (array $run): bool => str_contains($run['script'], '| databasify user grant '));

    expect($migration->fresh()->status)->toBe(MigrationStatus::Completed)
        ->and($database->schemas()->pluck('name'))->toContain('shop')
        ->and(collect(json_decode($ssh->engineRequestAt($grant), true)['grants'])->pluck('database'))->toContain('shop');
});

it('tolerates a few lost readings before failing with an alert', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $database = migratingDatabase('mysql');
    $migration = migrationOf($database, MigrationMode::Continuous, MigrationStatus::Syncing);
    fakeEngine(fakeSsh()->respondToEngine('migration progress', progressReply('lost', 'replication stopped')));

    foreach (range(1, 4) as $ignored) {
        resolve(RefreshMigration::class)->handle($migration->fresh());
    }
    expect($migration->fresh())->status->toBe(MigrationStatus::Syncing)->unhealthy_checks->toBe(4);

    foreach (range(1, 2) as $ignored) {
        resolve(RefreshMigration::class)->handle($migration->fresh());
    }

    expect($migration->fresh())->status->toBe(MigrationStatus::Failed)->detail->toContain('replication stopped');
    Event::assertDispatched(DatabaseAlertRaised::class);
});

it('fails the migration at once when the engine reports a failed copy', function () {
    $database = migratingDatabase('mysql');
    $migration = migrationOf($database, MigrationMode::Continuous);
    $ssh = fakeEngine(fakeSsh()->respondToEngine('migration progress', progressReply('failed', 'mysqlsh: access denied')));

    resolve(RefreshMigration::class)->handle($migration);

    expect($migration->fresh())->status->toBe(MigrationStatus::Failed)->detail->toContain('access denied')
        ->and(collect($ssh->runs)->contains(fn (array $run): bool => str_contains($run['script'], '| databasify migration stop ')))->toBeTrue();
});
