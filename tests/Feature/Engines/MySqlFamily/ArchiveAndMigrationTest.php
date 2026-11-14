<?php

declare(strict_types=1);

use App\Engines\Data\MigrationSource;
use App\Engines\Data\MigrationStart as MigrationStartData;
use App\Engines\Data\Storage;
use App\Enums\Migrations\MigrationPhase;
use App\Exceptions\Engines\EngineUnsupported;
use App\Models\DatabaseNode;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->scripts = fakeScripts();
});

function archivedNode(string $engine, string $case): DatabaseNode
{
    $fixture = goFixture("configure-{$case}.json", $engine);
    respondToFileGet(fakeSsh(), 'state/node.json', json_encode($fixture['request']));

    return idleDatabase($engine, 1, name: uniqid('db'), versionLine: $fixture['line'])->nodes()->sole();
}

it('reads the base backups and shipped binlogs like the Go engine', function () {
    $this->scripts->respond('mysql-family/archive/status', [
        'base' => [
            ['Path' => '20261110T030000Z.xbstream', 'ModTime' => '2026-11-10T03:05:00Z'],
            ['Path' => '20261110T030000Z.binlog-info', 'ModTime' => '2026-11-10T03:05:00Z'],
            ['Path' => '20261111T030000Z.xbstream', 'ModTime' => '2026-11-11T03:05:00Z'],
            ['Path' => '20261111T030000Z.binlog-info', 'ModTime' => '2026-11-11T03:05:00Z'],
            ['Path' => '20261112T030000Z.xbstream', 'ModTime' => '2026-11-12T03:05:00Z'],
        ],
        'shipped' => ['2026-11-12T08:15:30.5+06:00', '2026-11-11T09:00:00Z'],
        'backupResult' => 'exit-code',
    ]);

    $status = archivedNode('mysql', 'primary-9.7')->driver()->archive()->status(true);

    expect($status->backups)->toBe(2)
        ->and($status->earliest)->toEqual(CarbonImmutable::parse('2026-11-10T03:00:00Z'))
        ->and($status->lastBackup)->toEqual(CarbonImmutable::parse('2026-11-11T03:00:00Z'))
        ->and($status->latest)->toEqual(CarbonImmutable::parse('2026-11-12T02:15:30.5Z'))
        ->and($status->problem)->toBe('The last base backup failed, see the mysql-backup log.');
    $this->scripts->assertRan('mysql-family/archive/status', fn (string $script, array $env): bool => $env['RCLONE_REMOTE'] === ':s3,provider=Other,endpoint="https://s3.example.com:9000",region="us-east-1",force_path_style=true,no_check_bucket=true:bk/'
        && $env['STORAGE_PATH'] === 'db/x'
        && $env['RCLONE_S3_SECRET_ACCESS_KEY'] === "S'K"
        && $env['RCLONE_CONFIG'] === '/dev/null'
        && $env['SUFFIX'] === '.xbstream'
        && $env['BACKUP_UNIT'] === 'mysql-backup'
        && $env['BACK_UP_NOW'] === 'true'
        && str_contains($script, 'directory not found'));
});

it('does not archive a node configured without storage', function () {
    expect(fn () => archivedNode('mariadb', 'standalone-10.11')->driver()->archive()->status(false))
        ->toThrow(EngineUnsupported::class, 'this engine does not implement "archive status"');
});

it('restores to a point in time as a task', function (string $engine, string $suffix, string $fragment) {
    $storage = new Storage('https://s3.example.com', 'bk', 'us-east-1', 'AK', 'SK', true, '/db/x');

    idleDatabase($engine, 1, name: uniqid('db'))->nodes()->sole()->driver()->archive()->restore('restore.1', $storage, CarbonImmutable::parse('2026-11-12 09:30:00', 'Asia/Dhaka'));

    $this->scripts->assertRan("{$engine}/restore/start", fn (string $script, array $env): bool => $env['TARGET'] === '2026-11-12 03:30:00'
        && $env['TARGET_STAMP'] === '20261112T033000Z'
        && $env['TARGET_RFC3339'] === '2026-11-12T03:30:00Z'
        && $env['SUFFIX'] === $suffix
        && str_contains($script, 'no base backup was taken before')
        && str_contains($script, 'the base backup records no GTID position')
        && str_contains($script, $fragment));
})->with([
    'mysql' => ['mysql', '.xbstream', 'mysqlbinlog --exclude-gtids='],
    'mariadb' => ['mariadb', '.mbstream', 'mariadb-binlog --skip-gtid-strict-mode'],
]);

it('checks a MySQL source with the Go readiness rules', function () {
    $this->scripts->respond('mysql-family/migration/check', [
        'error' => null,
        'version' => '9.7.1-commercial',
        'visible' => ['app', 'shop'],
        'sizes' => ['app' => 3145728, 'shop' => 1048576],
        'localTables' => ['app' => 0, 'shop' => 4],
        'missingKeys' => ['app' => 2, 'shop' => 0],
        'gtidMode' => 'OFF',
        'grants' => ['GRANT REPLICATION SLAVE ON *.* TO `m`@`%`'],
        'localVersion' => '8.4.13',
        'roleEdgesReadable' => false,
    ]);
    $node = idleDatabase('mysql', 1, versionLine: '8.4')->nodes()->sole();

    $check = $node->driver()->migration()->check(new MigrationSource('10.0.0.9', 3306, 'm', "p'w", true), ['app', 'shop', 'gone']);

    expect($check->reachable)->toBeTrue()
        ->and($check->facts)->toMatchArray(['version' => '9.7.1', 'size_mb' => '4'])
        ->and($check->databases)->toBe(['app', 'shop'])
        ->and($check->occupied)->toBe(['shop'])
        ->and($check->blockers)->not->toBe([])
        ->and($check->warnings)->toBe([
            'app has 2 tables without a primary key, which a cluster refuses',
            'the source runs with gtid_mode OFF, so only a one-shot copy is possible',
            'the source runs 9.7.1, newer than this server; copying to an older version is not supported',
        ])
        ->and($check->problems)->toBe(['Databasify cannot read gone on the source.', 'MySQL Shell copies only as a source user with SELECT on mysql.* (it reads the roles), grant it and check again']);
    $this->scripts->assertRan('mysql-family/migration/check', fn (string $script, array $env): bool => $env['SOURCE_PASSWORD'] === "p'w" && $env['SOURCE_TLS'] === 'true' && ! str_contains($script, "p'w"));
});

it('checks a MySQL source for MariaDB as a one-shot copy only', function () {
    $this->scripts->respond('mysql-family/migration/check', [
        'error' => null, 'version' => '8.4.13', 'visible' => ['app'], 'sizes' => ['app' => 0], 'localTables' => ['app' => 0], 'missingKeys' => ['app' => 0],
        'logBin' => '1', 'binlogFormat' => 'ROW', 'grants' => ['GRANT ALL PRIVILEGES ON *.* TO `m`@`%`'], 'localVersion' => '11.8.3-MariaDB-ubu2404',
    ]);

    $check = idleDatabase('mariadb', 1, versionLine: '11.8')->nodes()->sole()->driver()->migration()->check(new MigrationSource('10.0.0.9', 3306, 'm', 'pw', false), []);

    expect($check->blockers)->not->toBe([])
        ->and($check->warnings)->toBe(['the source is MySQL, whose GTIDs MariaDB cannot follow, so only a one-shot copy is possible'])
        ->and($check->problems)->toBe([]);
});

it('reports an unreachable source with the first line of the error', function () {
    $this->scripts->respond('mysql-family/migration/check', ['error' => "ERROR 2003 (HY000): Can't connect to MySQL server on '10.0.0.9:3306' (111)"]);

    $check = idleDatabase('mysql', 1)->nodes()->sole()->driver()->migration()->check(new MigrationSource('10.0.0.9', 3306, 'm', 'pw', false), []);

    expect($check->reachable)->toBeFalse()->and($check->error)->toContain("Can't connect");
});

it('starts the copy job in its transient unit without the password on argv', function (string $engine) {
    idleDatabase($engine, 1, name: uniqid('db'))->nodes()->sole()->driver()->migration()->start(new MigrationStartData(new MigrationSource('10.0.0.9', 3306, 'm', "p'w", true), ['app', 'shop'], true, null));

    $this->scripts->assertRan('mysql-family/migration/start', fn (string $script, array $env): bool => $env['MIGRATION_PASSWORD'] === "p'w"
        && $env['MIGRATION_DATABASES'] === "app\nshop"
        && $env['MIGRATION_CONTINUOUS'] === 'true'
        && $env['MIGRATION_TLS'] === 'true'
        && $env['MIGRATION_UNIT'] === "{$engine}-migration"
        && str_contains((string) base64_decode($env['COPY_SCRIPT']), 'copy')
        && ! str_contains($script, "p'w"));
})->with(['mysql', 'mariadb']);

it('reads the migration progress and stops the copy', function (string $engine, string $ignored) {
    $this->scripts->respond('mysql-family/migration/progress', ['copy' => 'running', 'log' => 'copying shop', 'lagSeconds' => 3]);
    $node = idleDatabase($engine, 1, name: uniqid('db'))->nodes()->sole();

    $progress = $node->driver()->migration()->progress();
    $node->driver()->migration()->stop();

    expect($progress->phase)->toBe(MigrationPhase::Working)->and($progress->detail)->toBe('copying shop');
    $this->scripts->assertRan('mysql-family/migration/stop', fn (string $script): bool => str_contains($script, $ignored));
})->with([
    'mysql' => ['mysql', "'Error 3074 '"],
    'mariadb' => ['mariadb', "'Error 1617 '"],
]);

it('collects the source facts by running the real check script', function () {
    $local = localScripts()
        ->reply('SELECT @@version', '9.7.1')
        ->reply('FROM information_schema.SCHEMATA', '["app","mysql","shop"]')
        ->reply('SUM(data_length + index_length)', '2097152')
        ->reply("constraint_type = 'PRIMARY KEY'", '1')
        ->reply('SELECT COUNT(*) FROM information_schema.tables WHERE', '3')
        ->reply('SHOW GRANTS', 'GRANT REPLICATION SLAVE ON *.* TO `m`@`%`')
        ->reply('SELECT @@gtid_mode', 'ON')
        ->reply('mysql.role_edges', '1');

    $check = idleDatabase('mysql', 1, versionLine: '9.7')->nodes()->sole()->driver()->migration()->check(new MigrationSource('10.0.0.9', 3306, 'm', "p'w", false), ['shop']);

    expect($check->reachable)->toBeTrue()
        ->and($check->databases)->toBe(['app', 'shop'])
        ->and($check->facts['size_mb'])->toBe('2')
        ->and($check->occupied)->toBe(['shop'])
        ->and($check->blockers)->toBe([])
        ->and($check->warnings)->toBe(['shop has 1 tables without a primary key, which a cluster refuses'])
        ->and($check->problems)->toBe([])
        ->and($local->statements())->toContain("SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = 'shop'");
});

it('requires TLS to a MariaDB source when the source asks for it', function () {
    $this->scripts->respond('mysql-family/migration/check', ['error' => 'unreachable']);

    idleDatabase('mariadb', 1)->nodes()->sole()->driver()->migration()->check(new MigrationSource('10.0.0.9', 3306, 'm', 'pw', true), []);

    $this->scripts->assertRan('mysql-family/migration/check', fn (string $script): bool => str_contains($script, "printf 'ssl\\nskip-ssl-verify-server-cert\\n'"));
});
