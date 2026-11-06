<?php

declare(strict_types=1);

use App\Actions\Databases\Recovery\SyncRecoveryFiles;
use App\DatabaseProviders\Drivers\Mariadb\Mariadb;
use App\Enums\Databases\DatabaseMode;
use App\Models\Database;
use App\Models\StorageProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

function deployScriptOf(Database $database): string
{
    return view('ssh.databases.nodes.deploy', $database->provider()->scriptData()->deploy($database->seed()))->render();
}

it('archives postgres WAL with pgbackrest once point-in-time recovery is on', function () {
    $plain = idleDatabase('postgres', 1, DatabaseMode::Single, 'plain');
    $provider = StorageProvider::factory()->create(['endpoint' => 'https://minio.example.com:9443', 'path_style' => false]);
    $database = withRecovery(idleDatabase('postgres', 1, DatabaseMode::Single, 'orders'), ['retention_days' => 10], $provider);

    expect(renderedFile($plain, 'postgresql.conf'))->not->toContain('archive_')
        ->and(deployScriptOf($plain))->not->toContain('apt-get install -yq pgbackrest')
        ->and(renderedFile($database, 'postgresql.conf'))
        ->toContain("archive_mode = 'on'\n")
        ->toContain("archive_command = 'pgbackrest --config=/srv/postgres/conf/pgbackrest.conf --stanza=main archive-push %p'\n")
        ->toContain("archive_timeout = '60'\n");

    $config = fileWrittenIn(deployScriptOf($database), '/srv/postgres/conf/pgbackrest.conf');
    expect($config)
        ->toContain("repo1-type=s3\nrepo1-s3-endpoint=minio.example.com\nrepo1-storage-port=9443\nrepo1-s3-bucket=archives\n")
        ->toContain("repo1-s3-key=AKIDEXAMPLE\nrepo1-s3-key-secret=wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY\nrepo1-s3-uri-style=host\n")
        ->toContain("repo1-path=/postgres/{$database->dataKey()}\n")
        ->toContain("repo1-retention-full-type=time\nrepo1-retention-full=10\n")
        ->toContain("archive-async=y\n")
        ->toContain("[main]\npg1-path=/srv/postgres/data\npg1-port=25060\n");
});

it('keeps archiving in the patroni config when postgres becomes a cluster', function () {
    $database = withRecovery(idleDatabase('postgres', 3, name: 'orders'));

    expect(renderedFile($database, 'patroni.yml'))
        ->toContain("    archive_mode: 'on'\n")
        ->toContain("    archive_command: 'pgbackrest --config=/srv/postgres/conf/pgbackrest.conf --stanza=main archive-push %p'\n");
});

it('schedules the base backup on every postgres node', function () {
    $database = withRecovery(idleDatabase('postgres', 3, name: 'orders'), ['backup_hour' => 4]);
    $script = deployScriptOf($database);
    $manifest = json_decode(fileWrittenIn($script, '/srv/postgres/conf/processes.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($script)
        ->not->toContain('apt-get install')
        ->not->toContain('/etc/systemd/system/databasify-postgres-backup')
        ->and($manifest['schedules'])->toBe([[
            'name' => 'databasify-postgres-backup',
            'onCalendar' => '04:00',
            'user' => 'postgres',
            'timeout' => '24h',
            'argv' => ['/srv/postgres/archive/bin/backup'],
        ]])
        ->and(json_decode(fileWrittenIn(deployScriptOf(idleDatabase('postgres', 1, DatabaseMode::Single, 'plain')), '/srv/postgres/conf/processes.json'), true, flags: JSON_THROW_ON_ERROR))->not->toHaveKey('schedules')
        ->and(fileWrittenIn($script, '/srv/postgres/archive/bin/backup'))
        ->toContain('SELECT pg_is_in_recovery()')
        ->toContain('backrest stanza-create')
        ->toContain('backrest --type="$type" backup');
});

it('ships mysql binlogs every minute and takes xtrabackup base backups', function () {
    $database = withRecovery(idleDatabase('mysql', 1, DatabaseMode::Single, 'orders'), ['retention_days' => 3, 'backup_hour' => 1]);
    $script = deployScriptOf($database);
    $manifest = json_decode(fileWrittenIn($script, '/srv/mysql/conf/processes.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($script)
        ->not->toContain('percona-xtrabackup-84')->not->toContain('rclone.zip')
        ->not->toContain('/etc/systemd/system/databasify-mysql-')
        ->and($manifest['schedules'])->toBe([
            ['name' => 'binlog-archive', 'every' => '1m', 'timeout' => '1h', 'argv' => ['/srv/mysql/archive/bin/archive']],
            ['name' => 'binlog-backup', 'onCalendar' => '01:00', 'timeout' => '24h', 'argv' => ['/srv/mysql/archive/bin/backup']],
        ])
        ->and(fileWrittenIn($script, '/srv/mysql/conf/archive.env'))
        ->toContain("RCLONE_CONFIG=''\n")
        ->toContain("RCLONE_CONFIG_ARCHIVE_ENDPOINT='https://s3.example.com'\n")
        ->toContain("RCLONE_CONFIG_ARCHIVE_FORCE_PATH_STYLE='true'\n")
        ->toContain("ARCHIVE='archive:archives/mysql/{$database->dataKey()}'\n")
        ->toContain("RETENTION_DAYS='3'\n")
        ->and(fileWrittenIn($script, '/srv/mysql/archive/bin/archive'))
        ->toContain("REPLACE(@@GLOBAL.gtid_executed, '\\n', '')")
        ->toContain('FLUSH BINARY LOGS')
        ->toContain('rclone rcat "$ARCHIVE/binlogs/$closed-$server_id-$file.zst"')->not->toContain('flock')
        ->and(fileWrittenIn($script, '/srv/mysql/archive/bin/backup'))
        ->toContain('xtrabackup --defaults-extra-file=/srv/mysql/conf/root.cnf --datadir=/srv/mysql/data --backup --stream=xbstream')
        ->toContain('"$target/finished-$finished"');
});

it('shell-quotes the archive environment values', function () {
    $provider = StorageProvider::factory()->create(['endpoint' => "https://s3.example.com/it's"]);
    $database = withRecovery(idleDatabase('mysql', 1, DatabaseMode::Single, 'orders'), provider: $provider);

    expect(fileWrittenIn(deployScriptOf($database), '/srv/mysql/conf/archive.env'))
        ->toContain("RCLONE_CONFIG_ARCHIVE_ENDPOINT='https://s3.example.com/it'\\''s'\n");
});

it('ships mariadb binlogs and takes mariadb-backup base backups', function () {
    $database = withRecovery(idleDatabase('mariadb', 3, name: 'orders'));
    $script = deployScriptOf($database);

    expect($script)->not->toContain('percona')
        ->and(fileWrittenIn($script, '/srv/mariadb/archive/bin/archive'))->toContain('SELECT @@GLOBAL.gtid_binlog_pos')->toContain('SELECT @@GLOBAL.read_only')
        ->and(fileWrittenIn($script, '/srv/mariadb/archive/bin/backup'))->toContain('mariadb-backup --defaults-extra-file='.Mariadb::CONFIG.' --backup --stream=xbstream');
});

it('stops archiving and removes the keys when point-in-time recovery is off', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single, 'orders');
    $script = deployScriptOf($database);

    expect($script)
        ->not->toContain('archive.env\' 600')
        ->not->toContain('binlog-archive')
        ->toContain('if [ -d /srv/mysql/archive/bin ]; then')
        ->toContain('rm -rf /srv/mysql/conf/archive.env /srv/mysql/archive/bin');
});

it('writes the archive settings to every node without a restart', function () {
    $database = withRecovery(idleDatabase('postgres', 2, name: 'orders'));
    $ssh = fakeSsh();

    resolve(SyncRecoveryFiles::class)->handle($database);

    expect($ssh->runs)->toHaveCount(2)
        ->and(fileWrittenIn($ssh->scriptAt(1), '/srv/postgres/conf/pgbackrest.conf'))->toContain('repo1-retention-full=7')
        ->and(fileWrittenIn($ssh->scriptAt(0), '/srv/postgres/conf/processes.json'))->toContain('"name": "databasify-postgres-backup"')
        ->and($ssh->scriptAt(0))->toContain("if container_running databasify-postgres; then\n    ctl daemon-reload\nfi")->not->toContain('restart databasify-postgres');
});

it('removes the postgres archive keys and scripts when point-in-time recovery is off', function () {
    expect(deployScriptOf(idleDatabase('postgres', 1, DatabaseMode::Single, 'orders')))
        ->toContain('if [ -d /srv/postgres/archive/bin ]; then')
        ->toContain('rm -rf /srv/postgres/conf/pgbackrest.conf /srv/postgres/archive/bin /srv/postgres/archive/state /srv/postgres/archive/tmp')
        ->and(deployScriptOf(withRecovery(idleDatabase('postgres', 1, DatabaseMode::Single, 'kept'))))->not->toContain('turned off point-in-time recovery archiving');
});

it('removes the archive scripts once archiving is off and leaves no host timers behind', function () {
    $database = idleDatabase('mariadb', 1, DatabaseMode::Single);

    expect(view('ssh.databases.nodes.stop', $database->provider()->scriptData()->stopping(true))->render())
        ->not->toContain('/etc/systemd/system')
        ->and(view('ssh.databases.nodes.deploy', $database->provider()->scriptData()->deploy($database->seed()))->render())
        ->toContain('/srv/mariadb/archive/bin /srv/mariadb/archive/state /srv/mariadb/archive/tmp');
});

it('removes the archive folder once archiving is off and nothing else is left in it', function (array $leftovers, bool $removed) {
    $work = sys_get_temp_dir().'/archive-'.Str::random(8);
    mkdir("{$work}/bin", 0700, true);
    touch("{$work}/bin/archive");
    collect($leftovers)->each(fn (string $folder): bool => mkdir("{$work}/{$folder}", 0700, true));
    $script = view('ssh.databases.recovery.archive-off', ['archiveWork' => $work, 'archiveSecrets' => "{$work}.env"])->render();

    $result = Process::input("set -euo pipefail\n{$script}")->run(['bash']);

    expect($result->successful())->toBeTrue()
        ->and(is_dir($work))->toBe(! $removed);
    File::deleteDirectory($work);
})->with([
    'nothing left' => [[], true],
    'the pgbackrest log folder that postgres prepares' => [['log'], false],
]);
