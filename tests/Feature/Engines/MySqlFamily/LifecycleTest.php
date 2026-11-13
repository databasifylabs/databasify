<?php

declare(strict_types=1);

use App\Engines\Data\DefaultAccount;
use App\Engines\Data\Secrets;
use App\Exceptions\Engines\EngineUnsupported;
use App\Exceptions\Engines\InvalidEngineRequest;
use App\Models\DatabaseNode;

beforeEach(function () {
    config(['databasify.php_engines' => ['mysql', 'mariadb']]);
    $this->scripts = fakeScripts();
});

function lifecycleNode(string $engine): DatabaseNode
{
    return idleDatabase($engine, 1, name: uniqid('db'), versionLine: $engine === 'mysql' ? '8.4' : '11.8')->nodes()->sole();
}

it('starts, wakes and restarts a MySQL node the way the Go engine does', function () {
    $node = lifecycleNode('mysql');
    $node->driver()->nodes()->start();
    $node->driver()->nodes()->wake(all: false);
    $node->driver()->nodes()->restart();

    $this->scripts->assertRan('mysql/nodes/start', fn (string $script): bool => str_contains($script, 'unit_do start mysql-server') && str_contains($script, 'mysql-binlog-stream'))
        ->assertRan('mysql/nodes/wake', fn (string $script): bool => str_contains($script, 'reset-failed mysql-server') && str_contains($script, 'wait_until 1800'))
        ->assertRan('mysql-family/nodes/restart', fn (string $script): bool => str_contains($script, 'unit_do restart "$SERVER_UNIT"') && str_contains($script, "SERVER_UNIT='mysql-server'"));
});

it('wakes a MariaDB node by killing and stopping it, as the Go engine does', function () {
    lifecycleNode('mariadb')->driver()->nodes()->wake(all: false);

    $this->scripts->assertRan('mariadb/nodes/wake', fn (string $script): bool => str_contains($script, 'kill -s KILL mariadb-server') && str_contains($script, 'unit_do stop mariadb-server'));
});

it('starts the MariaDB helpers the saved request asks for', function () {
    lifecycleNode('mariadb')->driver()->nodes()->start();

    $this->scripts->assertRan('mariadb/nodes/start', fn (string $script): bool => str_contains($script, 'mariadb-cluster-watch') && str_contains($script, 'run node configure first'));
});

it('does not reset either engine, as the Go engines do not', function (string $engine) {
    expect(fn () => lifecycleNode($engine)->driver()->nodes()->reset(0, []))
        ->toThrow(EngineUnsupported::class, 'this engine does not implement "node reset"');
})->with(['mysql', 'mariadb']);

it('bootstraps the service users on the seed and then the default account', function (string $engine, string $fragment) {
    $this->scripts->respond("{$engine}/nodes/bootstrap", ['seed' => true])
        ->respond('mysql-family/accounts/create-user', ['created' => true]);

    lifecycleNode($engine)->driver()->nodes()->bootstrap(new Secrets('adm', "re'p", 'agent'), new DefaultAccount('defaultdb', 'databasify', 'pw'));

    $this->scripts->assertRan("{$engine}/nodes/bootstrap", fn (string $script, array $env): bool => $env['REPLICATION_PASSWORD'] === "re'p" && $env['AGENT_PASSWORD'] === 'agent' && str_contains($script, $fragment) && ! str_contains($script, "re'p"))
        ->assertRan('mysql-family/accounts/create-database', fn (string $script, array $env): bool => $env['NAME'] === 'defaultdb')
        ->assertRan('mysql-family/accounts/create-user', fn (string $script, array $env): bool => $env['NAME'] === 'databasify' && json_decode($env['GRANTS'], true) === [['database' => 'defaultdb', 'access' => 'write']]);
})->with([
    'mysql' => ['mysql', 'GRANT REPLICATION SLAVE, BACKUP_ADMIN, CLONE_ADMIN, GROUP_REPLICATION_STREAM, CONNECTION_ADMIN ON *.* TO'],
    'mariadb' => ['mariadb', 'INSERT IGNORE INTO databasify.writer VALUES (1, '],
]);

it('skips the default account on a node that is not the seed', function () {
    $this->scripts->respond('mysql/nodes/bootstrap', ['seed' => false]);

    lifecycleNode('mysql')->driver()->nodes()->bootstrap(new Secrets('adm', 'rep', 'agent'), new DefaultAccount('defaultdb', 'databasify', 'pw'));

    $this->scripts->assertNotRan('mysql-family/accounts/create-database');
});

it('refuses to bootstrap without the replication and agent secrets', function (string $engine, string $message) {
    expect(fn () => lifecycleNode($engine)->driver()->nodes()->bootstrap(new Secrets('adm', null, 'agent'), null))
        ->toThrow(InvalidEngineRequest::class, $message);
})->with([
    'mysql' => ['mysql', 'MySQL needs the replication and agent secrets'],
    'mariadb' => ['mariadb', 'MariaDB needs the replication and agent secrets'],
]);

it('installs TLS and reloads it with the engine statement', function (string $engine, string $reload) {
    [$certificate, $key] = selfSignedPair();

    lifecycleNode($engine)->driver()->nodes()->installTls($certificate, $key, $certificate);

    $this->scripts->assertRan('mysql-family/nodes/tls-install', fn (string $script, array $env): bool => $env['KEY'] === $key && str_contains($script, $reload) && str_contains($script, 'tls/server.key" 600 true'));
})->with([
    'mysql' => ['mysql', 'ALTER INSTANCE RELOAD TLS'],
    'mariadb' => ['mariadb', 'FLUSH SSL'],
]);

it('refuses a certificate that does not match its key', function () {
    [$certificate] = selfSignedPair();
    [, $otherKey] = selfSignedPair();

    expect(fn () => lifecycleNode('mysql')->driver()->nodes()->installTls($certificate, $otherKey, $certificate))
        ->toThrow(InvalidEngineRequest::class, 'the certificate and key are not a matching PEM pair');
});

it('waits for MySQL and rejoins its group as a task', function () {
    lifecycleNode('mysql')->driver()->nodes()->ready('ready.1', true);

    $this->scripts->assertRan('mysql/nodes/ready', fn (string $script): bool => str_contains($script, 'wait_ready MySQL')
        && str_contains($script, 'phase "waiting for $1"')
        && str_contains($script, '$READY_LABEL keeps stopping while it starts, see its log')
        && str_contains($script, "phase 'joining the group'")
        && str_contains($script, "START GROUP_REPLICATION USER = 'databasify_repl', PASSWORD = "));
});

it('waits for MariaDB to sync and keeps the writer role as a task', function () {
    $fixture = goFixture('configure-galera-11.8.json', 'mariadb');
    respondToFileGet(fakeSsh(), 'state/node.json', json_encode($fixture['request']));

    lifecycleNode('mariadb')->driver()->nodes()->ready('ready.1', false);

    $this->scripts->assertRan('mariadb/nodes/ready', fn (string $script, array $env): bool => str_contains($script, "phase 'syncing'")
        && str_contains($script, 'SELECT ip FROM databasify.writer WHERE id = 1')
        && base64_decode($env['MANIFEST']) === $fixture['manifest']);
});
