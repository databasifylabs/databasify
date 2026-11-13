<?php

declare(strict_types=1);

use App\Enums\Databases\NodeRole;

beforeEach(function () {
    config(['databasify.php_engines' => ['mysql', 'mariadb']]);
    $this->scripts = fakeScripts();
});

it('reads the role and version of a MySQL node', function () {
    $this->scripts->respond('mysql/nodes/status', ['role' => 'replica', 'version' => '8.4.13']);

    $status = idleDatabase('mysql', 1)->nodes()->sole()->driver()->nodes()->status();

    expect($status->role)->toBe(NodeRole::Replica)->and($status->version)->toBe('8.4.13');
    $this->scripts->assertRan('mysql/nodes/status', fn (string $script): bool => str_contains($script, "MEMBER_ID = @@server_uuid AND MEMBER_STATE = 'ONLINE'")
        && str_contains($script, 'SELECT @@super_read_only')
        && str_contains($script, "CHANNEL_NAME NOT LIKE 'group_replication%'")
        && str_contains($script, "MY_CLIENT='mysql'"));
});

it('reads the role and version of a MariaDB node', function () {
    $this->scripts->respond('mariadb/nodes/status', ['role' => 'primary', 'version' => '11.8.3']);

    $status = idleDatabase('mariadb', 1)->nodes()->sole()->driver()->nodes()->status();

    expect($status->role)->toBe(NodeRole::Primary)->and($status->version)->toBe('11.8.3');
    $this->scripts->assertRan('mariadb/nodes/status', fn (string $script): bool => str_contains($script, "VARIABLE_NAME = 'WSREP_LOCAL_STATE_COMMENT'")
        && str_contains($script, 'SELECT @@wsrep_on, @@read_only')
        && str_contains($script, "MY_CLIENT='mariadb'"));
});

it('reads an offline node without a version', function (string $engine) {
    $this->scripts->respond("{$engine}/nodes/status", ['role' => 'offline', 'version' => null]);

    $status = idleDatabase($engine, 1)->nodes()->sole()->driver()->nodes()->status();

    expect($status->role)->toBe(NodeRole::Offline)->and($status->version)->toBeNull();
    $this->scripts->assertRan("{$engine}/nodes/status", fn (string $script): bool => str_contains($script, '"role":"offline"'));
})->with(['mysql', 'mariadb']);

it('reads connections, queries and data size', function (string $engine) {
    $this->scripts->respond('mysql-family/nodes/metrics', ['connections' => 4, 'queries' => 1200, 'dataMb' => 38]);

    $metrics = idleDatabase($engine, 1)->nodes()->sole()->driver()->nodes()->metrics();

    expect($metrics)->connections->toBe(4)->queries->toBe(1200)->dataMb->toBe(38);
    $this->scripts->assertRan('mysql-family/nodes/metrics', fn (string $script): bool => str_contains($script, "VARIABLE_NAME IN ('Threads_connected', 'Questions')")
        && str_contains($script, 'SUM(data_length + index_length)'));
})->with(['mysql', 'mariadb']);
