<?php

declare(strict_types=1);

use App\Actions\Databases\Migrations\RefreshMigration;
use App\Actions\Databases\Migrations\StartMigration;
use App\Actions\Databases\Migrations\StopMigration;
use App\DTOs\Migrations\MigrationSource;
use App\Enums\Migrations\MigrationMode;
use App\Enums\Migrations\MigrationStatus;
use Illuminate\Validation\ValidationException;

it('copies once and cleans up on every engine', function (string $engine, array $outputs, string $cleanup) {
    $database = migratingDatabase($engine);
    $ssh = fakeSsh();
    collect($outputs)->each(fn (string $output) => $ssh->respond($output));

    $migration = resolve(StartMigration::class)->handle($database, new MigrationSource('source.example.com', 5000, 'migrator', 'secret'), MigrationMode::OneTime);
    expect($migration->fresh())->status->toBe(MigrationStatus::Copying)->detail->toBe('Copying from the source.');

    resolve(RefreshMigration::class)->handle($migration->fresh() ?? $migration);

    expect($migration->fresh())->status->toBe(MigrationStatus::Completed)->finished_at->not->toBeNull()
        ->and(collect($ssh->runs)->pluck('script')->implode("\n"))->toContain($cleanup)
        ->and($database->fresh()->operation)->toBeNull();
})->with([
    'mysql' => ['mysql', [mysqlSourceReport(), mysqlSourceReport(), '', "copy_exit=0\nposition={}\nlog=done\n"], "RESET REPLICA ALL FOR CHANNEL 'migration'"],
    'mariadb' => ['mariadb', ["reachable=yes\nversion=11.8.1\ngrants=GRANT RELOAD ON *.* TO `migrator`@`%`\n", '', "copy_exit=0\nposition=\nlog=done\n"], "RESET SLAVE 'migration' ALL"],
    'postgres' => ['postgres', ["reachable=yes\nversion=17.2\n", '', "copy_exit=0\nlog=done\n"], 'DROP SUBSCRIPTION'],
    'valkey' => ['valkey', ["valkey_version=8.1.1\n", '', "running=false\nexit_code=0\nlog=done\n"], 'databasify ctl stop valkey-migration'],
    'redis' => ['redis', ["redis_version=7.4.2\n", '', "running=false\nexit_code=0\nlog=done\n"], 'databasify ctl stop redis-migration'],
    'mongodb' => ['mongodb', ["reachable=yes\nversion=8.0.4\nreplica_set=\n", '', "running=false\nexit_code=0\nlog=done\n"], 'databasify ctl stop mongodb-migration'],
]);

it('follows the source after the copy and finishes on request on every engine', function (string $engine, array $outputs, ?int $lag, string $cleanup) {
    $database = migratingDatabase($engine);
    $ssh = fakeSsh();
    collect($outputs)->each(fn (string $output) => $ssh->respond($output));

    $migration = resolve(StartMigration::class)->handle($database, new MigrationSource('source.example.com', 5000, 'migrator', 'secret'), MigrationMode::Continuous);
    resolve(RefreshMigration::class)->handle($migration->fresh() ?? $migration);
    expect($migration->fresh())->status->toBe(MigrationStatus::Syncing)->detail->toBe('Copied, now following changes on the source.');

    resolve(RefreshMigration::class)->handle($migration->fresh() ?? $migration);
    expect($migration->fresh())->status->toBe(MigrationStatus::Syncing)->lag_seconds->toBe($lag);

    resolve(StopMigration::class)->handle($migration->fresh() ?? $migration, cutover: true);

    expect($migration->fresh())->status->toBe(MigrationStatus::Completed)
        ->and(collect($ssh->runs)->pluck('script')->implode("\n"))->toContain($cleanup);
})->with([
    'mysql' => ['mysql', [
        mysqlSourceReport(), mysqlSourceReport(), '',
        "copy_exit=0\nposition=".json_encode(['file' => 'binlog.000001', 'position' => '4'])."\n", '',
        "Replica_IO_Running=Yes\nReplica_SQL_Running=Yes\nSeconds_Behind_Source=2\n",
    ], 2, "RESET REPLICA ALL FOR CHANNEL 'migration'"],
    'mariadb' => ['mariadb', [
        "reachable=yes\nversion=11.8.1\nlog_bin=1\nbinlog_format=ROW\ngrants=GRANT REPLICATION SLAVE, BINLOG MONITOR, RELOAD ON *.* TO `m`@`%`\n", '',
        "copy_exit=0\nposition=bin.000001 4\n", '',
        "Slave_IO_Running=Yes\nSlave_SQL_Running=Yes\nSeconds_Behind_Master=3\n",
    ], 3, "RESET SLAVE 'migration' ALL"],
    'postgres' => ['postgres', [
        "reachable=yes\nversion=17.2\nwal_level=logical\ncan_replicate=t\n", '',
        "copy_exit=0\nsubscribed_tables=2\nready_tables=2\nlags=1 0\n",
        "copy_exit=0\nsubscribed_tables=2\nready_tables=2\nlags=0 4\n",
    ], 4, 'DROP SUBSCRIPTION'],
    'valkey' => ['valkey', [
        "valkey_version=8.1.1\n", '',
        "running=true\nconsistent=true\n",
        "running=true\nconsistent=true\n",
    ], 0, 'databasify ctl stop valkey-migration'],
    'redis' => ['redis', [
        "redis_version=7.4.2\n", '',
        "running=true\nconsistent=true\n",
        "running=true\nconsistent=true\n",
    ], 0, 'databasify ctl stop redis-migration'],
    'mongodb' => ['mongodb', [
        "reachable=yes\nversion=8.0.4\nreplica_set=rs0\n", '',
        "running=true\nphase=incremental\nlag=1\n",
        "running=true\nphase=incremental\nlag=2\n",
    ], 2, 'databasify ctl stop mongodb-migration'],
]);

it('counts a postgres subscription without an apply worker as a stall, not as caught up', function () {
    $database = migratingDatabase('postgres');
    $ssh = fakeSsh();
    collect([
        "reachable=yes\nversion=17.2\nwal_level=logical\ncan_replicate=t\n", '',
        "copy_exit=0\nsubscribed_tables=2\nready_tables=2\nlags=0 0\n",
        "copy_exit=0\nsubscribed_tables=2\nready_tables=2\nlags=0 unknown\n",
    ])->each(fn (string $output) => $ssh->respond($output));

    $migration = resolve(StartMigration::class)->handle($database, new MigrationSource('source.example.com', 5000, 'migrator', 'secret'), MigrationMode::Continuous);
    resolve(RefreshMigration::class)->handle($migration->fresh() ?? $migration);
    resolve(RefreshMigration::class)->handle($migration->fresh() ?? $migration);

    expect($migration->fresh())->status->toBe(MigrationStatus::Syncing)
        ->detail->toBe('The subscription is reconnecting to the source.')
        ->unhealthy_checks->toBe(1);
});

it('names at most ten occupied databases when it refuses to copy into them', function () {
    $database = migratingDatabase('mysql');
    $names = collect(range(1, 11))->map(fn (int $number): string => "shop{$number}");
    fakeSsh()->respond(mysqlSourceReport()."OCCUPIED {$names->implode(',')}\n");

    expect(fn () => resolve(StartMigration::class)->handle($database, new MigrationSource('source.example.com', 3306, 'migrator', 'secret', true, $names->all()), MigrationMode::OneTime))
        ->toThrow(ValidationException::class, 'already hold rows here, from an earlier copy or your own data: '.$names->take(10)->implode(', ').' and 1 more.');
});

it('refuses to copy into databases whose tables already hold rows here', function () {
    $database = migratingDatabase('mysql');
    fakeSsh()->respond(mysqlSourceReport()."SCHEMAS 'shop'\nOCCUPIED shop\n");

    expect(fn () => resolve(StartMigration::class)->handle($database, new MigrationSource('source.example.com', 3306, 'migrator', 'secret', true, ['shop']), MigrationMode::OneTime))
        ->toThrow(ValidationException::class, 'These databases already hold rows here, from an earlier copy or your own data: shop. Delete them on the Users & Databases tab, or drop them on the server yourself, or pick other databases.');
    expect($database->migrations()->count())->toBe(0);
});
