<?php

declare(strict_types=1);

use App\Actions\Databases\Migrations\CheckMigrationSource;
use App\DTOs\Migrations\MigrationSource;
use App\Models\User;

it('reports whether a mysql source can be followed continuously', function () {
    $database = migratingDatabase('mysql');
    fakeSsh()
        ->respond(mysqlSourceReport())
        ->respond(mysqlSourceReport(logBin: '0', grants: 'GRANT SELECT ON *.* TO `m`@`%`'))
        ->respond('REPORT '.json_encode(['reachable' => false, 'error' => "Access denied for user 'm'"])."\n");
    $source = new MigrationSource('db.example.com', 3306, 'migrator', 'secret');

    $ready = resolve(CheckMigrationSource::class)->handle($database, $source);
    $limited = resolve(CheckMigrationSource::class)->handle($database, $source);
    $denied = resolve(CheckMigrationSource::class)->handle($database, $source);

    expect($ready->supportsContinuous())->toBeTrue()
        ->and($ready->facts)->toMatchArray(['version' => '8.4.13', 'size_mb' => '12.5', 'consistent_snapshot' => 'yes'])
        ->and($limited->blockers)->toBe([
            'Binary logging is off on the source (log_bin).',
            'The source user needs the REPLICATION SLAVE privilege. Grant the REPLICATION SLAVE privilege to migrator on the source.',
            'The source user needs the REPLICATION CLIENT privilege. Grant the REPLICATION CLIENT privilege to migrator on the source.',
            'The source user needs RELOAD or BACKUP_ADMIN for a consistent snapshot. Grant RELOAD or BACKUP_ADMIN for a consistent snapshot to migrator on the source.',
        ])
        ->and($denied->reachable)->toBeFalse()
        ->and($denied->error)->toBe("Access denied for user 'm'");
});

it('reads valkey source facts and auth failures', function () {
    $database = migratingDatabase('valkey');
    $ssh = fakeSsh()
        ->respond("redis_version=7.2.16\nused_memory_human=1.03M\nrole=master\nkeys=6\n")
        ->respond("error=AUTH failed: WRONGPASS invalid username-password pair\n");
    $source = new MigrationSource('cache.example.com', 6379, '', 'secret', tls: false);

    $report = resolve(CheckMigrationSource::class)->handle($database, $source);

    expect($report->facts)->toMatchArray(['version' => '7.2.16', 'keys' => '6'])
        ->and($report->supportsContinuous())->toBeTrue()
        ->and(resolve(CheckMigrationSource::class)->handle($database, $source)->error)->toStartWith('AUTH failed')
        ->and($ssh->scriptAt(0))->toContain('valkey-cli -h cache.example.com -p 6379')->not->toContain('--tls')->not->toContain('secret');
});

it('validates migration sources per engine over http', function () {
    $this->actingAs(User::factory()->create());
    $valkey = migratingDatabase('valkey');
    $mysql = migratingDatabase('mysql');

    $this->postJson("/databases/{$valkey->id}/migrations/check", ['host' => 'cache.example.com', 'port' => 6379, 'databases' => ['x']])
        ->assertUnprocessable()->assertJsonValidationErrors('databases');
    $this->postJson("/databases/{$mysql->id}/migrations/check", ['host' => 'db.example.com; rm -rf /', 'port' => 3306])
        ->assertUnprocessable()->assertJsonValidationErrors(['host', 'username', 'password']);

    fakeSsh()->respond(mysqlSourceReport());
    $this->postJson("/databases/{$mysql->id}/migrations/check", ['host' => 'db.example.com', 'port' => 3306, 'username' => 'migrator', 'password' => 'secret', 'databases' => ['shop']])
        ->assertOk()->assertJson(['reachable' => true, 'continuous' => true]);
});
