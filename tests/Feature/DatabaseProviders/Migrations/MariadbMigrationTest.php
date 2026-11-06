<?php

declare(strict_types=1);

use App\Actions\Databases\Migrations\StartMigration;
use App\DTOs\Migrations\MigrationPosition;
use App\DTOs\Migrations\MigrationSource;
use App\Enums\Migrations\MigrationMode;
use App\Enums\Migrations\MigrationPhase;
use App\Jobs\Databases\RunMigrationJob;
use App\Services\Ssh\Ssh;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->ssh = fakeSsh();
    $this->database = migratingDatabase('mariadb');
    $this->migration = $this->database->provider()->migration();
});

it('asks for credentials and protects the system schemas', function () {
    expect($this->migration->form()->defaultPort)->toBe(3306)
        ->and($this->migration->form()->rules()['databases.*'])->toContain('not_in:mysql,sys,performance_schema,information_schema');
});

it('checks the source with encoded credentials and the chosen tls mode', function () {
    $ssh = $this->ssh
        ->respond("reachable=yes\nversion=11.4.2-MariaDB\nlog_bin=1\nbinlog_format=row\ngrants=GRANT ALL PRIVILEGES ON *.* TO `m`@`%`\nsize_mb=40\n")
        ->respond("reachable=no\nerror=ERROR 2002 (HY000): Can't connect\n")
        ->respond('');

    $ready = $this->migration->check(new MigrationSource('db.example.com', 3306, "m'x", 'top-secret', false));
    $refused = $this->migration->check(new MigrationSource('db.example.com', 3306, 'm', 'secret'));
    $silent = $this->migration->check(new MigrationSource('db.example.com', 3306, 'm', 'secret'));

    expect($ready->supportsContinuous())->toBeTrue()
        ->and($ready->facts)->toBe(['version' => '11.4.2-MariaDB', 'size_mb' => '40', 'binlog_format' => 'row'])
        ->and($ssh->scriptAt(0))->toContain(base64_encode("m'x"))->toContain(base64_encode('top-secret'))->not->toContain('top-secret')->toContain("SOURCE_SSL='--skip-ssl'")
        ->and($ssh->scriptAt(1))->toContain("SOURCE_SSL='--ssl --skip-ssl-verify-server-cert'")
        ->and($refused->error)->toBe("ERROR 2002 (HY000): Can't connect")
        ->and($silent->error)->toBe('No answer from the source.');
});

it('accepts the replica spelling of the replication grant and the older name of binlog monitor', function () {
    $this->ssh->respond("reachable=yes\nversion=11.8.1-MariaDB\nlog_bin=1\nbinlog_format=ROW\ngrants=GRANT REPLICATION REPLICA, REPLICATION CLIENT, RELOAD ON *.* TO `m`@`%`\n");

    expect($this->migration->check(new MigrationSource('db.example.com', 3306, 'm', 'secret'))->blockers)->toBe([]);
});

it('refuses a continuous migration when the source user lacks binlog monitor', function () {
    Queue::fake([RunMigrationJob::class]);
    $this->ssh->respond("reachable=yes\nversion=11.4.2-MariaDB\nlog_bin=1\nbinlog_format=ROW\ngrants=GRANT REPLICATION SLAVE, RELOAD ON *.* TO `m`@`%`\n");

    expect(fn () => resolve(StartMigration::class)->handle($this->database, new MigrationSource('db.example.com', 3306, 'm', 'secret'), MigrationMode::Continuous))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors()['mode'])->toBe([
            'Continuous sync is not possible with this source:',
            'The source user needs the BINLOG MONITOR privilege (REPLICATION CLIENT before MariaDB 10.5). Grant the BINLOG MONITOR privilege (REPLICATION CLIENT before MariaDB 10.5) to m on the source.',
        ]));
    Queue::assertNothingPushed();
});

it('refuses every migration when the source user lacks reload, which the dump needs', function () {
    Queue::fake([RunMigrationJob::class]);
    $this->ssh->respond("reachable=yes\nversion=11.4.2-MariaDB\nlog_bin=1\nbinlog_format=ROW\ngrants=GRANT REPLICATION SLAVE, BINLOG MONITOR ON *.* TO `m`@`%`\n");

    expect(fn () => resolve(StartMigration::class)->handle($this->database, new MigrationSource('db.example.com', 3306, 'm', 'secret'), MigrationMode::OneTime))
        ->toThrow(ValidationException::class, 'The source user needs the RELOAD privilege. Grant the RELOAD privilege to m on the source.');
    Queue::assertNothingPushed();
});

it('starts the dump in the background for the chosen databases', function () {
    $migration = migrationOf($this->database, MigrationMode::Continuous);
    $migration->update(['source' => new MigrationSource('db.example.com', 3306, 'm', 'secret', true, ['shop', 'blog'])]);
    $ssh = $this->ssh;

    $this->migration->start($migration->fresh() ?? $migration);

    expect($ssh->scriptAt(0))->toContain("MIGRATION_DATABASES='shop blog'")->toContain('--master-data=2')->toContain('databasify ctl run --quiet --collect --unit=mariadb-migration')->toContain('echo "copying from db.example.com:3306 in the background"')
        ->and($this->database->serverLogs()->sole()->name)->toBe("{$migration->host()->server->name}: starting the copy from db.example.com:3306");
});

it('reads copy progress and the replication connection', function () {
    $migration = migrationOf($this->database, MigrationMode::Continuous);
    $this->ssh
        ->respond("copy=running\nlog=copying shop\n")
        ->respond("copy=lost\nlog=Killed\n")
        ->respond("copy_exit=2\nposition=\nlog=mariadb-dump: Got error\n")
        ->respond("log=\n")
        ->respond("Slave_IO_Running=Connecting\nSlave_SQL_Running=Yes\nLast_IO_Error=error connecting to master\nLast_SQL_Error=\n")
        ->respond("Slave_IO_Running=No\nSlave_SQL_Running=No\nLast_IO_Error=\nLast_SQL_Error=\n")
        ->respond("Slave_IO_Running=Yes\nSlave_SQL_Running=Yes\nSeconds_Behind_Master=7\nLast_IO_Error=\nLast_SQL_Error=\n");

    $progress = collect(range(1, 7))->map(fn () => $this->migration->progress($migration));

    expect($progress[0])->phase->toBe(MigrationPhase::Working)->detail->toBe('Copying: copying shop')
        ->and($progress[1])->phase->toBe(MigrationPhase::Broken)->detail->toBe('The copy stopped unexpectedly: Killed')
        ->and($progress[2])->phase->toBe(MigrationPhase::Broken)->detail->toBe('The copy failed: mariadb-dump: Got error')
        ->and($progress[3])->phase->toBe(MigrationPhase::Broken)->detail->toBe('Neither the copy nor the replication connection is running.')
        ->and($progress[4])->phase->toBe(MigrationPhase::Stalled)->detail->toBe('error connecting to master')
        ->and($progress[5])->phase->toBe(MigrationPhase::Stalled)->detail->toBe('The migration connection is reconnecting to the source.')
        ->and($progress[6])->phase->toBe(MigrationPhase::CaughtUp)->detail->toBe('Replicating, 7s behind the source.')->lagSeconds->toBe(7);
});

it('records the dumped binlog position and follows it', function () {
    $migration = migrationOf($this->database, MigrationMode::Continuous);
    $ssh = $this->ssh->respond("copy_exit=0\nposition=mariadb-bin.000002 1234\nlog=copied every database\n");

    $progress = $this->migration->progress($migration);
    $this->migration->follow($migration->fresh() ?? $migration);

    $sql = sqlSentIn($ssh->scriptAt(1));
    expect($progress)->phase->toBe(MigrationPhase::CaughtUp)->detail->toBe('Copied every database from the source.')
        ->and($migration->fresh()?->position)->toEqual(new MigrationPosition(binlog: 'mariadb-bin.000002 1234'))
        ->and($sql)->toStartWith("CHANGE MASTER 'migration' TO\n")
        ->toContain("MASTER_PASSWORD = 'pa\\'ss\\\\word',")
        ->toContain("MASTER_LOG_FILE = 'mariadb-bin.000002',\n    MASTER_LOG_POS = 1234,")
        ->toContain('MASTER_SSL = 1,')
        ->toEndWith("SET GLOBAL migration.replicate_wild_ignore_table = 'mysql.%';\nSTART SLAVE 'migration';\n")
        ->and($ssh->scriptAt(1))->toContain("RESET SLAVE 'migration' ALL")->toContain("rm -rf '/srv/mariadb/migration'")
        ->and($ssh->runs[1]['timeout'])->toBe(Ssh::TIMEOUT);
});

it('refuses to follow a dump without a binlog position', function () {
    $migration = migrationOf($this->database, MigrationMode::Continuous);
    $migration->update(['position' => new MigrationPosition(binlog: 'mariadb-bin.000002')]);
    $ssh = $this->ssh;

    expect(fn () => $this->migration->follow($migration))->toThrow(RuntimeException::class, 'no binlog position')
        ->and($ssh->runs)->toBe([]);
});

it('stops the copy and resets the replication connection', function () {
    $ssh = $this->ssh;

    $this->migration->stop(migrationOf($this->database, MigrationMode::Continuous));

    expect($ssh->scriptAt(0))->toContain('databasify ctl stop mariadb-migration')->toContain("STOP SLAVE 'migration'; RESET SLAVE 'migration' ALL")->toContain('rm -rf "$state"');
});

it('replicates only the chosen databases and restores the filter after a restart', function () {
    $migration = migrationOf($this->database, MigrationMode::Continuous);
    $migration->update(['source' => new MigrationSource('db.example.com', 3306, 'migrator', 'secret', true, ['shop', 'my_app']), 'position' => new MigrationPosition(binlog: 'mariadb-bin.000002 1234')]);
    $this->ssh->respond('')->respond("Slave_IO_Running=Yes\nSlave_SQL_Running=Yes\nSeconds_Behind_Master=0\n");

    $this->migration->follow($migration->fresh() ?? $migration);
    $this->migration->progress($migration->fresh() ?? $migration);

    $progress = $this->ssh->scriptAt(1);
    preg_match("/echo '([A-Za-z0-9+\\/=]+)' \\| base64 -d \\| mariadb/", $progress, $match);
    expect(sqlSentIn($this->ssh->scriptAt(0)))->toContain("SET GLOBAL migration.replicate_wild_do_table = 'shop.%,my\\\\_app.%';\nSTART SLAVE 'migration';")
        ->and($progress)->toContain('SELECT CONCAT(@@migration.replicate_wild_do_table, @@migration.replicate_wild_ignore_table)')
        ->and(base64_decode($match[1] ?? '', true))->toBe("STOP SLAVE 'migration';\nSET GLOBAL migration.replicate_wild_do_table = 'shop.%,my\\\\_app.%';\nSTART SLAVE 'migration';\n")
        ->and(bashErrorsIn($progress))->toBe('');
});

it('dumps only the source databases whose names Databasify can manage when none were selected', function () {
    $this->migration->start(migrationOf($this->database, MigrationMode::OneTime));

    expect($this->ssh->scriptAt(0))->toContain("--setenv=SCHEMA_NAME='^[A-Za-z][A-Za-z0-9_]*$'")
        ->toContain('databases=$(printf \'%s\n\' "$listed" | { grep -Ex "$SCHEMA_NAME" || true; } | paste -sd \' \' -)')
        ->toContain('skipped=$(printf \'%s\n\' "$listed" | { grep -Evx "$SCHEMA_NAME" || true; } | paste -sd \' \' -)')
        ->and(bashErrorsIn($this->ssh->scriptAt(0)))->toBe('');
});
