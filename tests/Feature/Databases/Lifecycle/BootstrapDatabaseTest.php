<?php

declare(strict_types=1);

use App\Actions\Databases\Lifecycle\BootstrapDatabase;
use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\NodeRole;
use App\Exceptions\Ssh\RemoteScriptFailed;
use App\Jobs\Databases\BootstrapDatabaseJob;

it('bootstraps a valkey HA database node by node', function () {
    $database = newDatabase('valkey', 3);
    $ssh = fakeSsh()->respond(certificateOutput('ca.crt', 'server.pem', 'ca.key'));
    foreach (range(1, 12) as $ignored) {
        $ssh->respond();
    }
    $ssh->respond("role:master\nconnected_slaves:2\n")->respond("role:slave\nmaster_link_status:up\nmaster_sync_in_progress:0\n")->respond("role:slave\nmaster_link_status:up\nmaster_sync_in_progress:0\n");

    runJob(new BootstrapDatabaseJob($database));

    $database->refresh();
    expect($database->status)->toBe(DatabaseStatus::Ready)
        ->and($database->settings->certificate('ca.crt'))->toBe('ca.crt-content')
        ->and($database->settings->certificate('ca.key'))->toBe('ca.key-content')
        ->and($database->nodes->pluck('role')->all())->toBe([NodeRole::Primary, NodeRole::Replica, NodeRole::Replica])
        ->and($database->operation)->toBeNull()
        ->and($database->serverLogs()->pluck('output')->implode(''))->not->toContain(base64_encode('ca.key-content'));

    [$seed] = $database->nodes->all();
    expect(fileWrittenIn($ssh->scriptAt(1), '/srv/valkey/conf/valkey.conf'))->toContain("replica-announce-ip {$seed->privateIp()}\n")->not->toContain('replicaof')
        ->and(fileWrittenIn($ssh->scriptAt(1), '/srv/valkey/sentinel/sentinel.conf'))->toContain("sentinel monitor main {$seed->privateIp()} 6379 2\n")
        ->and(fileWrittenIn($ssh->scriptAt(2), '/srv/valkey/conf/valkey.conf'))->toContain("replicaof {$seed->privateIp()} 6379\n")
        ->and($ssh->scriptAt(4))->toContain('nftables')
        ->and($ssh->scriptAt(7))->toContain("processes='databasify-valkey databasify-valkey-sentinel'")->toContain('databasify ctl restart "$process"')
        ->and($ssh->scriptAt(8))->toContain("grep -q '^role:master'")->toContain("ss -ltn | grep -q ':25061 '")
        ->and($ssh->scriptAt(10))->toContain('master_link_status:up');
});

it('finishes the mysql seed and creates the app database', function () {
    $database = newDatabase('mysql', 1, DatabaseMode::Single);
    $ssh = fakeSsh()->respond(certificateOutput('ca.crt', 'server.crt', 'server.key', 'ca.key'));
    foreach (range(1, 5) as $ignored) {
        $ssh->respond();
    }
    $ssh->respond("PRIMARY ONLINE\n");

    resolve(BootstrapDatabase::class)->handle($database);

    expect(fileWrittenIn($ssh->scriptAt(1), '/srv/mysql/conf/init.sql'))->toContain("ALTER USER 'root'@'localhost' IDENTIFIED BY '{$database->settings->secret('MYSQL_ROOT_PASSWORD')}';")
        ->and($ssh->scriptAt(3))->toContain('databasify ctl restart mysql')->not->toContain('dba.create_cluster')
        ->and($ssh->scriptAt(5))->toContain('msql_from')->not->toContain($database->settings->secret('DB_PASSWORD'))
        ->and(sqlSentIn($ssh->scriptAt(5)))->toStartWith('CREATE DATABASE IF NOT EXISTS `defaultdb`;')->toContain("IDENTIFIED WITH caching_sha2_password BY '{$database->settings->secret('DB_PASSWORD')}';")
        ->and($ssh->scriptAt(4))->toContain('WHERE MEMBER_ID = @@server_uuid')
        ->and($database->fresh()->status)->toBe(DatabaseStatus::Ready);
});

it('keeps a database that failed to bootstrap marked failed', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $database->update(['status' => DatabaseStatus::Failed]);
    $ssh = fakeSsh();

    expect(resolve(RefreshDatabaseStatus::class)->handle($database))->toBe(DatabaseStatus::Failed)
        ->and($ssh->runs)->toBeEmpty();
});

it('marks the database failed and frees it when a bootstrap step fails', function () {
    $database = newDatabase('valkey', 1, DatabaseMode::Single);
    $ssh = fakeSsh()->respond('openssl: command not found', 1);

    expect(fn () => runJob(new BootstrapDatabaseJob($database)))->toThrow(RemoteScriptFailed::class, 'failed with exit code 1')
        ->and($ssh->runs)->toHaveCount(1)
        ->and($database->fresh())->status->toBe(DatabaseStatus::Failed)->operation->toBeNull();
});
