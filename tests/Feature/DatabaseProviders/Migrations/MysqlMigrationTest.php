<?php

declare(strict_types=1);

use App\Actions\Databases\Migrations\StartMigration;
use App\DatabaseProviders\Groups\EngineMigration;
use App\DTOs\Migrations\MigrationPosition;
use App\DTOs\Migrations\MigrationSource;
use App\Enums\Migrations\MigrationMode;
use App\Enums\Migrations\MigrationPhase;
use App\Enums\Migrations\MigrationStatus;
use App\Jobs\Databases\RunMigrationJob;
use App\Services\Ssh\Ssh;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->ssh = fakeSsh();
    $this->database = migratingDatabase('mysql');
    $this->migration = $this->database->provider()->migration();
});

it('asks for credentials and protects the system schemas', function () {
    expect($this->migration->form()->toArray())->toBe(['default_port' => 3306, 'credentials_required' => true, 'selects_databases' => true])
        ->and($this->migration->form()->rules()['databases.*'])->toContain('not_in:mysql,sys,performance_schema,information_schema');
});

it('checks the source with mysqlsh without putting the password in the config', function () {
    $ssh = $this->ssh->respond(mysqlSourceReport(grants: 'GRANT ALL PRIVILEGES ON *.* TO `m`@`%`'));

    $report = $this->migration->check(new MigrationSource('db.example.com', 3307, 'migrator', 'top-secret', false, ['shop']));

    expect($report->supportsContinuous())->toBeTrue()
        ->and($report->facts)->toBe(['version' => '8.4.13', 'size_mb' => '12.5', 'gtid_mode' => 'ON', 'binlog_format' => 'ROW', 'consistent_snapshot' => 'yes'])
        ->and($ssh->scriptAt(0))->toContain(base64_encode('top-secret'))->not->toContain('top-secret')
        ->toContain('cfg = json.loads(r"""{"host":"db.example.com","port":3307,"user":"migrator","tls":false,"databases":["shop"]}""")')
        ->toContain('mysqlsh --no-defaults --no-wizard --py')->not->toContain('--js')
        ->toContain("'binlog_expire_logs_seconds', 'activate_all_roles_on_login')")
        ->and($ssh->runs[0]['timeout'])->toBe(Ssh::PROBE_TIMEOUT);
});

it('blocks statement binlogs and reports raw output when mysqlsh prints no report', function () {
    $this->ssh
        ->respond('REPORT '.json_encode(['reachable' => true, 'version' => '8.0.36', 'log_bin' => '1', 'binlog_format' => 'STATEMENT', 'gtid_mode' => 'OFF', 'size_mb' => '1', 'grants' => 'GRANT REPLICATION SLAVE, BACKUP_ADMIN ON *.* TO `m`@`%`'])."\n")
        ->respond("  mysqlsh: command not found\n");
    $source = new MigrationSource('db.example.com', 3306, 'migrator', 'secret');

    $statement = $this->migration->check($source);
    $broken = $this->migration->check($source);

    expect($statement->blockers)->toBe(['The source must use binlog_format=ROW.', 'Turn on GTIDs on the source (gtid_mode=ON) to keep it in sync.', 'The source user needs the REPLICATION CLIENT privilege. Grant the REPLICATION CLIENT privilege to migrator on the source.'])
        ->and($statement->fact('consistent_snapshot'))->toBe('yes')
        ->and($broken->reachable)->toBeFalse()
        ->and($broken->error)->toBe('mysqlsh: command not found');
});

it('refuses a continuous migration when the source has no gtids or lacks replication client', function () {
    Queue::fake([RunMigrationJob::class]);
    $this->ssh->respond(mysqlSourceReport(grants: 'GRANT SELECT, RELOAD, REPLICATION SLAVE ON *.* TO `m`@`%`', gtidMode: 'OFF_PERMISSIVE'));

    expect(fn () => resolve(StartMigration::class)->handle($this->database, new MigrationSource('db.example.com', 3306, 'migrator', 'secret'), MigrationMode::Continuous))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors()['mode'])->toBe([
            'Continuous sync is not possible with this source:',
            'Turn on GTIDs on the source (gtid_mode=ON) to keep it in sync.',
            'The source user needs the REPLICATION CLIENT privilege. Grant the REPLICATION CLIENT privilege to migrator on the source.',
        ]));
    Queue::assertNothingPushed();
});

it('tells the user to redeploy an in-app source whose default user predates its replication grants', function () {
    $source = idleDatabase('mysql', 1, name: 'orders');
    $this->ssh->respond(mysqlSourceReport(grants: 'GRANT SELECT, RELOAD, REPLICATION SLAVE ON *.* TO `databasify`@`%`'));

    $report = $this->migration->check(new MigrationSource($source->seed()->privateIp(), 3306, $source->settings->defaultUsername(), 'secret'));

    expect($report->blockers)->toBe(['The source user needs the REPLICATION CLIENT privilege. Redeploy orders to grant it.']);
});

it('needs the source user to read the role table the copy reads its roles from', function (string $rolesOnLogin, string $grants, array $problems) {
    $source = idleDatabase('mysql', 1, name: 'orders');
    $this->ssh->respond('REPORT '.json_encode(['reachable' => true, 'version' => '9.7.2', 'log_bin' => '1', 'binlog_format' => 'ROW', 'gtid_mode' => 'ON', 'activate_all_roles_on_login' => $rolesOnLogin, 'grants' => $grants])."\n");

    $report = $this->migration->check(new MigrationSource($source->seed()->privateIp(), 3306, $source->settings->defaultUsername(), 'secret'));

    expect($report->problems)->toBe($problems);
})->with([
    'our default user, roles activated by default' => ['OFF', 'GRANT REPLICATION SLAVE, REPLICATION CLIENT ON *.* TO `databasify`@`%` GRANT BACKUP_ADMIN ON *.* TO `databasify`@`%` GRANT ALL PRIVILEGES ON `defaultdb`.* TO `databasify`@`%` GRANT SELECT ON `mysql`.`default_roles` TO `databasify`@`%` GRANT SELECT ON `mysql`.`role_edges` TO `databasify`@`%`', []],
    'our default user, every role activated on login' => ['ON', 'GRANT REPLICATION SLAVE, REPLICATION CLIENT ON *.* TO `databasify`@`%` GRANT SELECT ON `mysql`.`default_roles` TO `databasify`@`%` GRANT SELECT ON `mysql`.`role_edges` TO `databasify`@`%`', []],
    'select on every schema' => ['OFF', 'GRANT SELECT, RELOAD, REPLICATION SLAVE, REPLICATION CLIENT ON *.* TO `databasify`@`%`', []],
    'all privileges with every role activated on login' => ['ON', 'GRANT ALL PRIVILEGES ON *.* TO `databasify`@`%`', []],
    'only default_roles with every role activated on login' => ['ON', 'GRANT REPLICATION SLAVE, REPLICATION CLIENT ON *.* TO `databasify`@`%` GRANT SELECT ON `mysql`.`default_roles` TO `databasify`@`%`', ['The source user needs SELECT on mysql.role_edges. Redeploy orders to grant it.']],
    'only role_edges with roles activated by default' => ['OFF', 'GRANT REPLICATION SLAVE, REPLICATION CLIENT ON *.* TO `databasify`@`%` GRANT SELECT ON `mysql`.`role_edges` TO `databasify`@`%`', ['The source user needs SELECT on mysql.default_roles. Redeploy orders to grant it.']],
    'select on its own schemas only' => ['OFF', 'GRANT REPLICATION SLAVE, REPLICATION CLIENT ON *.* TO `databasify`@`%` GRANT ALL PRIVILEGES ON `defaultdb`.* TO `databasify`@`%` GRANT SELECT ON `mysql`.`user` TO `databasify`@`%`', ['The source user needs SELECT on mysql.default_roles. Redeploy orders to grant it.']],
]);

it('refuses selected databases the source user cannot see, which the copy would not find', function () {
    $this->ssh->respond('REPORT '.json_encode(['reachable' => true, 'version' => '9.7.2', 'log_bin' => '1', 'binlog_format' => 'ROW', 'gtid_mode' => 'ON', 'grants' => 'GRANT ALL PRIVILEGES ON *.* TO `m`@`%`', 'schemas' => ['shop', 'hidden'], 'missing' => ['hidden']])."\n");

    $report = $this->migration->check(new MigrationSource('db.example.com', 3306, 'migrator', 'secret', true, ['shop', 'hidden']));

    expect($report->problems)->toBe(['The source has no database hidden, or migrator cannot see it. Grant migrator SELECT on it, or pick other databases.'])
        ->and($this->ssh->scriptAt(0))->toContain("'missing': [name for name in cfg['databases'] if name not in visible]");
});

it('reads the migration channel status in vertical mode, since the mysql 9.7 client rejects \\G in -e', function () {
    $database = idleDatabase('mysql', 1, name: 'current', versionLine: '9.7');
    $engine = $database->provider();

    expect(view($engine->scriptView('migrations.replicate'), [...$engine->scriptData()->for(), 'stateDir' => '/srv/mysql/migration', 'sql' => 'START REPLICA;'])->render())
        ->toContain("--vertical -e \"SHOW REPLICA STATUS FOR CHANNEL 'migration'\"")
        ->not->toContain('\\G');
});

it('grants the default user both role tables a copy may read', function () {
    expect($this->database->provider()->accounts()?->defaultAccountSql())
        ->toContain("GRANT SELECT ON mysql.default_roles TO '{$this->database->settings->defaultUsername()}'@'%';")
        ->toContain("GRANT SELECT ON mysql.role_edges TO '{$this->database->settings->defaultUsername()}'@'%';");
});

it('allows a one-time migration without gtid', function () {
    Queue::fake([RunMigrationJob::class]);
    $this->ssh->respond(mysqlSourceReport(gtidMode: 'OFF'));

    $migration = resolve(StartMigration::class)->handle($this->database, new MigrationSource('db.example.com', 3306, 'migrator', 'secret'), MigrationMode::OneTime);

    expect($migration->status)->toBe(MigrationStatus::Copying);
    Queue::assertPushed(RunMigrationJob::class, 1);
});

it('warns without blocking when the source keeps binlogs for less than 14 days', function (string $seconds, array $warnings) {
    $this->ssh->respond(mysqlSourceReport(binlogExpireSeconds: $seconds));

    $report = $this->migration->check(new MigrationSource('db.example.com', 3306, 'migrator', 'secret'));

    expect($report->supportsContinuous())->toBeTrue()
        ->and($report->warnings)->toBe($warnings)
        ->and($this->ssh->scriptAt(0))->toContain("'binlog_expire_logs_seconds'");
})->with([
    'three days' => ['259200', ['The source keeps binary logs for less than 14 days (binlog_expire_logs_seconds). Keep them at least 14 days so a long copy can still catch up.']],
    'fourteen days' => ['1209600', []],
    'never expiring' => ['0', []],
]);

it('blocks continuous sync when the source password is longer than mysql replication accepts', function () {
    $this->ssh->respond(mysqlSourceReport(grants: 'GRANT ALL PRIVILEGES ON *.* TO `m`@`%`'));

    $report = $this->migration->check(new MigrationSource('db.example.com', 3306, 'migrator', str_repeat('p', 33)));

    expect($report->supportsContinuous())->toBeFalse()
        ->and($report->blockers)->toBe(['MySQL replication takes passwords of at most 32 characters, so give the source user a shorter one.']);
});

it('copies without a consistent snapshot only when a one-time source cannot lock', function () {
    $migration = migrationOf($this->database, MigrationMode::OneTime);
    $ssh = $this->ssh
        ->respond('REPORT '.json_encode(['reachable' => true, 'version' => '5.7.44', 'log_bin' => '0', 'binlog_format' => 'ROW', 'gtid_mode' => 'OFF', 'size_mb' => '1', 'grants' => 'GRANT SELECT ON *.* TO `m`@`%`'])."\n")
        ->respond("copying from db.example.com:3306 in the background\n");

    $this->migration->start($migration);

    expect($migration->fresh()?->position)->toEqual(new MigrationPosition)
        ->and($ssh->scriptAt(1))->toContain('"dumpDir":"/srv/mysql/migration/dump","consistent":false')->toContain('util.dump_schemas(cfg[\'databases\'], cfg[\'dumpDir\'], dump)')->toContain("'ignoreExistingObjects': True")->toContain('--py --quiet-start=2 --file=$state/migration.py')
        ->toContain("MySQL Shell is missing from this node's engine image")
        ->toContain('echo "copying from db.example.com:3306 in the background"')
        ->and($this->database->serverLogs()->sole()->name)->toBe("{$migration->host()->server->name}: starting the copy from db.example.com:3306");
});

it('reads copy progress, failures and the replication channel', function () {
    $migration = migrationOf($this->database, MigrationMode::Continuous);
    $this->ssh
        ->respond("copy=running\nlog=dumping shop.orders\n")
        ->respond("copy=lost\nlog=Killed\n")
        ->respond("copy_exit=1\nlog=ERROR: Access denied\n")
        ->respond('')
        ->respond("Replica_IO_Running=Connecting\nReplica_SQL_Running=Yes\nSeconds_Behind_Source=NULL\nLast_IO_Error=\nLast_SQL_Error=\n")
        ->respond("Replica_IO_Running=Yes\nReplica_SQL_Running=Yes\nSeconds_Behind_Source=NULL\n");

    $progress = collect(range(1, 6))->map(fn () => $this->migration->progress($migration));

    expect($progress[0])->phase->toBe(MigrationPhase::Working)->detail->toBe('Copying: dumping shop.orders')
        ->and($progress[1])->phase->toBe(MigrationPhase::Broken)->detail->toBe('The copy stopped unexpectedly: Killed')
        ->and($progress[2])->phase->toBe(MigrationPhase::Broken)->detail->toBe('The copy failed: ERROR: Access denied')
        ->and($progress[3])->phase->toBe(MigrationPhase::Broken)->detail->toBe('Neither the copy nor the replication channel is running.')
        ->and($progress[4])->phase->toBe(MigrationPhase::Stalled)->detail->toBe('The migration channel is reconnecting to the source.')
        ->and($progress[5])->phase->toBe(MigrationPhase::CaughtUp)->lagSeconds->toBeNull();
});

it('records the dumped position even when it cannot read it', function () {
    $migration = migrationOf($this->database, MigrationMode::Continuous);
    $this->ssh->respond("copy_exit=0\nposition=not-json\nlog=done\n");

    $progress = $this->migration->progress($migration);

    expect($progress->phase)->toBe(MigrationPhase::CaughtUp)
        ->and($migration->fresh()?->position)->toEqual(new MigrationPosition(file: '', position: ''));
});

it('refuses to follow without a binlog position', function () {
    $migration = migrationOf($this->database, MigrationMode::Continuous);
    $migration->update(['position' => new MigrationPosition(file: 'binlog.000001', position: 'abc')]);
    $ssh = $this->ssh;

    expect(fn () => $this->migration->follow($migration))->toThrow(RuntimeException::class, 'no binlog position')
        ->and($ssh->runs)->toBe([]);
});

it('follows sources over plain connections by their binlog position', function () {
    $migration = migrationOf($this->database, MigrationMode::Continuous);
    $migration->update(['source' => new MigrationSource('db.example.com', 3306, 'migrator', 'secret', false), 'position' => new MigrationPosition(file: 'binlog.000009', position: '4')]);
    $ssh = $this->ssh;

    $this->migration->follow($migration);

    expect(sqlSentIn($ssh->scriptAt(0)))->toContain('SOURCE_SSL = 0,')->toContain('SOURCE_LOG_POS = 4,')->not->toContain('ASSIGN_GTIDS_TO_ANONYMOUS_TRANSACTIONS')
        ->and($ssh->scriptAt(0))->toContain('wait_for 120 replicating')
        ->and($ssh->runs[0]['timeout'])->toBe(Ssh::TIMEOUT);
});

it('stops the copy and removes the channel and state', function () {
    $ssh = $this->ssh;

    $this->migration->stop(migrationOf($this->database, MigrationMode::Continuous));

    expect($ssh->scriptAt(0))->toContain('stop_copy')->toContain("RESET REPLICA ALL FOR CHANNEL 'migration'")->toContain('in_mysql rm -rf "$state"')->toContain('state=/srv/mysql/migration')
        ->and($ssh->runs[0]['timeout'])->toBe(EngineMigration::STOP_TIMEOUT);
});

it('replicates only the chosen databases, or everything but the system schemas', function () {
    $migration = migrationOf($this->database, MigrationMode::Continuous);
    $migration->update(['source' => new MigrationSource('db.example.com', 3306, 'migrator', 'secret', true, ['shop', 'my_app']), 'position' => new MigrationPosition(file: 'binlog.000009', position: '4')]);
    $everything = migrationOf($this->database, MigrationMode::Continuous);
    $everything->update(['position' => new MigrationPosition(file: 'binlog.000009', position: '4')]);

    $this->migration->follow($migration->fresh() ?? $migration);
    $this->migration->follow($everything->fresh() ?? $everything);

    expect(sqlSentIn($this->ssh->scriptAt(0)))->toContain("CHANGE REPLICATION FILTER REPLICATE_WILD_DO_TABLE = ('shop.%', 'my\\\\_app.%') FOR CHANNEL 'migration';\nSTART REPLICA FOR CHANNEL 'migration';")
        ->and(sqlSentIn($this->ssh->scriptAt(1)))->toContain("CHANGE REPLICATION FILTER REPLICATE_WILD_IGNORE_TABLE = ('mysql.%', 'sys.%') FOR CHANNEL 'migration';");
});

it('puts the replication filter back when the server lost it while following', function () {
    $migration = migrationOf($this->database, MigrationMode::Continuous);
    $migration->update(['source' => new MigrationSource('db.example.com', 3306, 'migrator', 'secret', true, ['shop'])]);
    $this->ssh->respond("Replica_IO_Running=Yes\nReplica_SQL_Running=Yes\nSeconds_Behind_Source=0\n");

    $this->migration->progress($migration->fresh() ?? $migration);

    $script = $this->ssh->scriptAt(0);
    preg_match("/echo '([A-Za-z0-9+\\/=]+)' \\| base64 -d \\| timeout 60 mysql/", $script, $match);
    expect($script)->toContain("FROM performance_schema.replication_applier_filters WHERE channel_name = 'migration'")->toContain('if [ "$filters" = 0 ]; then')
        ->and(base64_decode($match[1] ?? '', true))->toBe("STOP REPLICA SQL_THREAD FOR CHANNEL 'migration';\nCHANGE REPLICATION FILTER REPLICATE_WILD_DO_TABLE = ('shop.%') FOR CHANNEL 'migration';\nSTART REPLICA SQL_THREAD FOR CHANNEL 'migration';\n")
        ->and(bashErrorsIn($script))->toBe('');
});

it('lists the databases whose tables already hold rows on this server, so empty tables do not block', function () {
    $this->ssh->respond(mysqlSourceReport()."SCHEMAS 'shop','blog'\nOCCUPIED shop,blog\n")->respond(mysqlSourceReport()."SCHEMAS 'shop'\nOCCUPIED \n");

    $taken = $this->migration->check(new MigrationSource('db.example.com', 3306, 'migrator', 'secret', true, ['shop', 'blog']));
    $free = $this->migration->check(new MigrationSource('db.example.com', 3306, 'migrator', 'secret', true, ['shop']));

    expect($taken->occupied)->toBe(['shop', 'blog'])
        ->and($free->occupied)->toBe([])
        ->and($this->ssh->scriptAt(0))->toContain("WHERE table_type = 'BASE TABLE' AND table_schema IN (\$schemas)")->toContain("LIMIT 1;')")->toContain('| target_sql | target_sql | sort -u')
        ->and(bashErrorsIn($this->ssh->scriptAt(0)))->toBe('');
});
