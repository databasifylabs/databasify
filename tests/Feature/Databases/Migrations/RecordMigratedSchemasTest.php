<?php

declare(strict_types=1);

use App\Actions\Databases\Migrations\EndMigration;
use App\Actions\Databases\Migrations\FailMigration;
use App\Actions\Databases\Migrations\RecordMigratedSchemas;
use App\Actions\Databases\Migrations\RefreshMigration;
use App\Actions\Databases\Migrations\StartMigration;
use App\DatabaseProviders\Groups\EngineAccounts;
use App\DTOs\Migrations\MigrationPosition;
use App\DTOs\Migrations\MigrationSource;
use App\Enums\Migrations\MigrationMode;
use App\Enums\Migrations\MigrationStatus;
use App\Enums\Servers\ServerLogStatus;
use App\Events\DatabaseAlertRaised;
use App\Jobs\Databases\RunMigrationJob;
use App\Models\Database;
use App\Models\DatabaseMigration;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * @param  list<string>  $databases
 */
function migrationCopying(Database $database, MigrationMode $mode, MigrationStatus $status, array $databases): DatabaseMigration
{
    $migration = migrationOf($database, $mode, $status);
    $migration->update(['source' => new MigrationSource('db.example.com', 5432, 'migrator', 'secret', databases: $databases)]);

    return $migration->fresh() ?? $migration;
}

function postgresCheck(string $databases): string
{
    return "reachable=yes\nversion=17.2\nwal_level=logical\ncan_replicate=t\nsize_mb=3\ndatabases={$databases}\n";
}

function mariadbCheck(string $databases): string
{
    return "reachable=yes\nversion=11.4.2-MariaDB\nlog_bin=1\nbinlog_format=ROW\ngrants=GRANT ALL PRIVILEGES ON *.* TO `m`@`%`\nsize_mb=40\ndatabases={$databases}\n";
}

function mongodbCheck(string $databases): string
{
    return "reachable=yes\nversion=8.0.4\nreplica_set=rs0\nsize_mb=1\ndatabases={$databases}\n";
}

/**
 * @param  list<string>  $schemas
 */
function mysqlCheck(array $schemas): string
{
    return 'REPORT '.json_encode(['reachable' => true, 'version' => '8.4.13', 'log_bin' => '1', 'binlog_format' => 'ROW', 'gtid_mode' => 'ON', 'size_mb' => '1', 'grants' => 'GRANT ALL PRIVILEGES ON *.* TO `m`@`%`', 'schemas' => $schemas])."\n";
}

it('records the databases a finished one-time copy made and grants them to the default user only', function () {
    $database = migratingDatabase('mysql');
    $database->users()->create(['name' => 'reporting', 'password' => 'secret-pass']);
    $migration = migrationCopying($database, MigrationMode::OneTime, MigrationStatus::Copying, ['shop', 'mysql', 'analytics']);
    $ssh = fakeSsh()->respond("copy_exit=0\nposition=\nlog=copied\n");

    resolve(RefreshMigration::class)->handle($migration);

    expect($migration->fresh()?->status)->toBe(MigrationStatus::Completed)
        ->and($database->schemaNames())->toBe(['analytics', 'defaultdb', 'shop'])
        ->and(sqlSentIn($ssh->scriptAt(1)))->toBe(
            "CREATE DATABASE IF NOT EXISTS `shop`;\nGRANT ALL PRIVILEGES ON `shop`.* TO 'databasify'@'%';\n"
            ."CREATE DATABASE IF NOT EXISTS `analytics`;\nGRANT ALL PRIVILEGES ON `analytics`.* TO 'databasify'@'%';\n",
        );
});

it('records the copied databases when a continuous migration is cut over', function (string $engine, string $system) {
    $database = migratingDatabase($engine);
    $migration = migrationCopying($database, MigrationMode::Continuous, MigrationStatus::Syncing, ['shop', $system]);
    fakeSsh();

    resolve(EndMigration::class)->handle($migration, cutover: true);

    expect($migration->fresh()?->status)->toBe(MigrationStatus::Completed)
        ->and($database->schemaNames())->toBe(['defaultdb', 'shop']);
})->with([
    ['postgres', 'template1'],
    ['mysql', 'performance_schema'],
    ['mariadb', 'information_schema'],
    ['mongodb', 'admin'],
]);

it('records each copied database once and keeps existing ones', function () {
    $database = migratingDatabase('postgres');
    $database->schemas()->create(['name' => 'shop']);
    $migration = migrationCopying($database, MigrationMode::Continuous, MigrationStatus::Syncing, ['shop', 'orders', 'orders', 'defaultdb']);
    fakeSsh();

    resolve(EndMigration::class)->handle($migration, cutover: true);

    expect($database->schemaNames())->toBe(['defaultdb', 'orders', 'shop']);
});

it('keeps an empty selection unfiltered for the engine and records what the source holds at completion', function (string $engine, string $atStart, string $atCompletion) {
    $database = migratingDatabase($engine);
    Queue::fake([RunMigrationJob::class]);
    fakeSsh()->respond($atStart)->respond($atCompletion);

    $migration = resolve(StartMigration::class)->handle($database, new MigrationSource('db.example.com', 5432, 'migrator', 'secret'), MigrationMode::OneTime);
    $migration->update(['status' => MigrationStatus::Syncing]);
    resolve(EndMigration::class)->handle($migration, cutover: true);

    expect($migration->fresh()?->source)->databases->toBe([])->discovered->toBe(['blog', 'shop'])
        ->and($database->schemaNames())->toBe(['blog', 'defaultdb', 'news', 'shop']);
})->with([
    'postgres' => ['postgres', postgresCheck('blog shop'), postgresCheck('blog news shop')],
    'mysql' => ['mysql', mysqlCheck(['blog', 'shop']), mysqlCheck(['blog', 'news', 'shop'])],
    'mariadb' => ['mariadb', mariadbCheck('blog shop'), mariadbCheck('blog news shop')],
    'mongodb' => ['mongodb', mongodbCheck('blog,shop'), mongodbCheck('blog,news,shop')],
]);

it('replicates every mysql database when none were selected', function () {
    $database = migratingDatabase('mysql');
    Queue::fake([RunMigrationJob::class]);
    $ssh = fakeSsh()->respond(mysqlCheck(['blog', 'shop']));

    $migration = resolve(StartMigration::class)->handle($database, new MigrationSource('db.example.com', 3306, 'migrator', 'secret'), MigrationMode::Continuous);
    $migration->update(['position' => new MigrationPosition(file: 'binlog.000009', position: '4')]);
    $database->provider()->migration()?->follow($migration->fresh() ?? $migration);

    expect(sqlSentIn($ssh->scriptAt(1)))->toContain("CHANGE REPLICATION FILTER REPLICATE_WILD_IGNORE_TABLE = ('mysql.%', 'sys.%') FOR CHANNEL 'migration';")
        ->not->toContain('REPLICATE_WILD_DO_TABLE');
});

it('falls back to the databases found at start when the source is unreachable at completion', function () {
    $database = migratingDatabase('postgres');
    Queue::fake([RunMigrationJob::class]);
    fakeSsh()->respond(postgresCheck('blog shop'))->respond("reachable=no\nerror=timeout\n");

    $migration = resolve(StartMigration::class)->handle($database, new MigrationSource('db.example.com', 5432, 'migrator', 'secret'), MigrationMode::OneTime);
    $migration->update(['status' => MigrationStatus::Syncing]);
    resolve(EndMigration::class)->handle($migration, cutover: true);

    expect($database->schemaNames())->toBe(['blog', 'defaultdb', 'shop']);
});

it('records nothing twice when two completions race, and grants idempotently each time', function () {
    $database = migratingDatabase('mysql');
    $migration = migrationCopying($database, MigrationMode::Continuous, MigrationStatus::Completed, ['shop']);
    $ssh = fakeSsh();

    resolve(RecordMigratedSchemas::class)->handle($migration);
    resolve(RecordMigratedSchemas::class)->handle($migration);

    expect($database->schemaNames())->toBe(['defaultdb', 'shop'])
        ->and($ssh->runs)->toHaveCount(2);
});

it('lets only the first of two overlapping refreshes finish a one-time copy', function () {
    $database = migratingDatabase('mysql');
    $migration = migrationCopying($database, MigrationMode::OneTime, MigrationStatus::Copying, ['shop']);
    $stale = $migration->fresh() ?? $migration;
    Event::fake([DatabaseAlertRaised::class]);
    $ssh = fakeSsh()->respond("copy_exit=0\nposition=\nlog=copied\n")->respond()->respond()->respond("copy_exit=1\nposition=\nlog=mongodump: cursor killed\n");

    resolve(RefreshMigration::class)->handle($migration);
    resolve(RefreshMigration::class)->handle($stale);

    expect($migration->fresh())->status->toBe(MigrationStatus::Completed)->detail->toBe('Copied every table from the source.')
        ->and($database->schemaNames())->toBe(['defaultdb', 'shop'])
        ->and($ssh->runs)->toHaveCount(4);
    Event::assertNotDispatched(DatabaseAlertRaised::class);
});

it('lets a stop that wins the race keep a finishing copy from completing', function () {
    $database = migratingDatabase('mysql');
    $migration = migrationCopying($database, MigrationMode::OneTime, MigrationStatus::Copying, ['shop']);
    $stale = $migration->fresh() ?? $migration;
    $ssh = fakeSsh()->respond()->respond()->respond("copy_exit=0\nposition=\nlog=copied\n");

    resolve(EndMigration::class)->handle($migration, cutover: false);
    resolve(RefreshMigration::class)->handle($stale);

    expect($migration->fresh())->status->toBe(MigrationStatus::Stopped)->detail->toBe('Stopped before finishing.')
        ->and($database->schemaNames())->toBe(['defaultdb'])
        ->and($ssh->runs)->toHaveCount(3);
});

it('leaves a copy that completed first alone when a stale stop ends', function () {
    $database = migratingDatabase('mysql');
    $migration = migrationCopying($database, MigrationMode::OneTime, MigrationStatus::Copying, ['shop']);
    $stale = $migration->fresh() ?? $migration;
    $ssh = fakeSsh()->respond("copy_exit=0\nposition=\nlog=copied\n");

    resolve(RefreshMigration::class)->handle($migration);
    $runsBeforeStop = count($ssh->runs);
    resolve(EndMigration::class)->handle($stale, cutover: false);

    expect($migration->fresh())->status->toBe(MigrationStatus::Completed)->detail->toBe('Copied every table from the source.')
        ->and($database->schemaNames())->toBe(['defaultdb', 'shop'])
        ->and($ssh->runs)->toHaveCount($runsBeforeStop);
});

it('records nothing when a migration stops before finishing', function () {
    $database = migratingDatabase('mysql');
    $migration = migrationCopying($database, MigrationMode::Continuous, MigrationStatus::Syncing, ['shop']);
    fakeSsh();

    resolve(EndMigration::class)->handle($migration, cutover: false);

    expect($database->schemaNames())->toBe(['defaultdb']);
});

it('records nothing for engines without databases', function () {
    $database = migratingDatabase('valkey');
    $migration = migrationCopying($database, MigrationMode::OneTime, MigrationStatus::Copying, ['shop']);
    fakeSsh()->respond("running=false\nexit_code=0\nconsistent=false\nlog=done\n");

    resolve(RefreshMigration::class)->handle($migration);

    expect($migration->fresh()?->status)->toBe(MigrationStatus::Completed)
        ->and($database->schemas()->count())->toBe(0);
});

it('skips and logs source database names outside the schema charset, never rendering them', function (string $engine, string $atStart, string $atCompletion, string $hostile) {
    $database = migratingDatabase($engine);
    Queue::fake([RunMigrationJob::class]);
    $ssh = fakeSsh()->respond($atStart)->respond($atCompletion);

    $migration = resolve(StartMigration::class)->handle($database, new MigrationSource('db.example.com', 5432, 'migrator', 'secret'), MigrationMode::OneTime);
    $migration->update(['status' => MigrationStatus::Syncing]);
    resolve(EndMigration::class)->handle($migration, cutover: true);

    $rendered = collect($ssh->runs)->map(fn (array $run): string => $run['script'].sqlSentIn($run['script']))->implode("\n");

    expect($database->schemaNames())->toBe(['defaultdb', 'shop'])
        ->and($rendered)->not->toContain($hostile)
        ->and($database->serverLogs()->where('name', 'Record migrated databases')->where('status', ServerLogStatus::Failed)->sole()->output)
        ->toContain("Skipped {$hostile}: Databasify only records databases whose names start with a letter and contain only letters, digits and underscores.");
})->with([
    'postgres' => ['postgres', postgresCheck('shop'), postgresCheck('shop x";DROP'), 'x";DROP'],
    'mysql' => ['mysql', mysqlCheck(['shop']), mysqlCheck(['shop', 'x`; DROP DATABASE mysql; --']), 'x`; DROP DATABASE mysql; --'],
    'mariadb' => ['mariadb', mariadbCheck('shop'), mariadbCheck('shop x`;DROP'), 'x`;DROP'],
    'mongodb' => ['mongodb', mongodbCheck('shop'), mongodbCheck("shop,x');db.dropDatabase();('"), "x');db.dropDatabase();('"],
]);

it('skips selected names outside the schema charset too', function () {
    $database = migratingDatabase('mysql');
    $migration = migrationCopying($database, MigrationMode::Continuous, MigrationStatus::Syncing, ['shop', 'my-app']);
    fakeSsh();

    resolve(EndMigration::class)->handle($migration, cutover: true);

    expect($database->schemaNames())->toBe(['defaultdb', 'shop'])
        ->and($database->serverLogs()->where('name', 'Record migrated databases')->sole()->output)->toContain('Skipped my-app:');
});

it('records the databases a stopped copy created on the target so they can be deleted', function () {
    $database = migratingDatabase('mysql');
    $migration = migrationCopying($database, MigrationMode::OneTime, MigrationStatus::Copying, ['shop', 'blog', 'news']);
    $ssh = fakeSsh()->respond("migration stopped and cleaned up\n")->respond("user:databasify\ndatabase:defaultdb\ndatabase:shop\ndatabase:blog\ndatabase:mysql\n");

    resolve(EndMigration::class)->handle($migration, cutover: false);

    expect($migration->fresh()?->status)->toBe(MigrationStatus::Stopped)
        ->and($database->schemaNames())->toBe(['blog', 'defaultdb', 'shop'])
        ->and($ssh->runs)->toHaveCount(2)
        ->and($ssh->scriptAt(1))->toContain("CONCAT('database:', schema_name)");
});

it('records the databases a failed copy created on the target, from the names found at start', function () {
    $database = migratingDatabase('postgres');
    $migration = migrationCopying($database, MigrationMode::OneTime, MigrationStatus::Copying, []);
    $migration->update(['source' => $migration->source->withDiscovered(['blog', 'shop', 'bad-name'])]);
    Event::fake([DatabaseAlertRaised::class]);
    fakeSsh()->respond()->respond("database:postgres\ndatabase:defaultdb\ndatabase:blog\ndatabase:bad-name\n");

    resolve(FailMigration::class)->handle($migration->fresh() ?? $migration, 'The copy failed: pg_restore: error');

    expect($database->schemaNames())->toBe(['blog', 'defaultdb'])
        ->and($database->serverLogs()->where('name', 'Record migrated databases')->sole()->output)->toContain('Skipped bad-name:');
});

it('grants the default user every copied database again when a completion is retried', function () {
    $database = migratingDatabase('mysql');
    $migration = migrationCopying($database, MigrationMode::Continuous, MigrationStatus::Completed, ['shop']);
    $ssh = fakeSsh()->respond('ssh: connect timed out', 255)->respond();

    resolve(RecordMigratedSchemas::class)->handle($migration);
    resolve(RecordMigratedSchemas::class)->handle($migration);

    expect($database->schemaNames())->toBe(['defaultdb', 'shop'])
        ->and($ssh->runs)->toHaveCount(2)
        ->and(sqlSentIn($ssh->scriptAt(1)))->toContain("GRANT ALL PRIVILEGES ON `shop`.* TO 'databasify'@'%';")
        ->and($ssh->runs[1]['timeout'])->toBe(EngineAccounts::GRANT_TIMEOUT)
        ->and(EngineAccounts::GRANT_TIMEOUT)->toBeLessThanOrEqual(120);
});
