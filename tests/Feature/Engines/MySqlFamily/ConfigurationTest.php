<?php

declare(strict_types=1);

use App\Engines\EngineClient;
use App\Engines\Requests\ConfigApply;
use App\Engines\Requests\ConfigFields;
use App\Engines\Requests\ConfigShow;
use App\Engines\Requests\ConfigSync;
use App\Engines\Requests\ConfigTune;
use App\Exceptions\Engines\EngineBusy;
use App\Exceptions\Engines\InvalidEngineRequest;
use App\Models\DatabaseNode;

beforeEach(function () {
    config(['databasify.php_engines' => ['mysql', 'mariadb']]);
    $this->scripts = fakeScripts()->respond('mysql-family/nodes/configure', ['changed' => true]);
});

function configuredNode(string $engine, string $line): DatabaseNode
{
    return idleDatabase($engine, 1, name: uniqid('db'), versionLine: $line)->nodes()->sole();
}

function decodedFiles(array $env): array
{
    return array_map(fn (array $file): array => ['path' => $file['path'], 'mode' => octdec($file['mode']), 'content' => base64_decode($file['content'])], json_decode($env['FILES'], true));
}

it('configures a MySQL node with the Go-identical files and its first-start SQL', function (string $case) {
    fakeSsh()->respondToEngine('file get', ['contentBase64' => '', 'exists' => false]);
    $fixture = goFixture("configure-{$case}.json", 'mysql');

    $reply = resolve(EngineClient::class)->send(configuredNode('mysql', $fixture['line']), configureFromFixture($fixture['request']));

    expect($reply->changed)->toBeTrue();
    $this->scripts->assertRan('mysql-family/nodes/configure', fn (string $script, array $env): bool => decodedFiles($env) == $fixture['files']
        && base64_decode($env['MANIFEST']) === $fixture['manifest']
        && json_decode($env['TUNED_JSON'], true)['innodb_buffer_pool_size'] === '2560M'
        && json_decode($env['REQUEST_JSON'], true)['ip'] === $fixture['request']['ip']
        && $env['INIT_SQL'] === "ALTER USER 'root'@'localhost' IDENTIFIED BY '".str_replace(['\\', "'"], ['\\\\', "''"], $fixture['request']['secrets']['admin'])."';\n"
        && $env['INIT_COMMAND'] === '/usr/sbin/mysqld --no-defaults --initialize-insecure --user=mysql --datadir=/srv/mysql/data --init-file=/srv/mysql/conf/init.sql'
        && str_contains($script, 'grouped.cnf')
        && str_contains($script, 'unit_do daemon-reload'));
})->with(['standalone-8.4', 'primary-9.7']);

it('configures a MariaDB node with both manifests so a seed starts a new cluster', function () {
    fakeSsh()->respondToEngine('file get', ['contentBase64' => '', 'exists' => false]);
    $fixture = goFixture('configure-seed-11.4.json', 'mariadb');

    resolve(EngineClient::class)->send(configuredNode('mariadb', '11.4'), configureFromFixture($fixture['request']));

    $this->scripts->assertRan('mysql-family/nodes/configure', fn (string $script, array $env): bool => decodedFiles($env) == $fixture['files']
        && base64_decode($env['MANIFEST_NEW_CLUSTER']) === $fixture['manifest']
        && ! str_contains(base64_decode($env['MANIFEST']), '--wsrep-new-cluster')
        && $env['INIT_SQL'] === $fixture['init']
        && $env['SEED'] === 'true'
        && $env['HELPERS'] === 'mariadb-cluster-watch'
        && str_contains($env['INIT_COMMAND'], 'mariadb-install-db --no-defaults --user=mysql --datadir=/srv/mariadb/data --skip-test-db --auth-root-authentication-method=socket --extra-file=/srv/mariadb/conf/init.sql'));
});

it('starts the binlog stream of a MySQL node that archives', function () {
    fakeSsh()->respondToEngine('file get', ['contentBase64' => '', 'exists' => false]);
    $fixture = goFixture('configure-primary-9.7.json', 'mysql');

    resolve(EngineClient::class)->send(configuredNode('mysql', '9.7'), configureFromFixture($fixture['request']));

    $this->scripts->assertRan('mysql-family/nodes/configure', fn (string $script, array $env): bool => $env['HELPERS'] === 'mysql-binlog-stream' && $env['SEED'] === 'false');
});

it('refuses missing secrets with the Go wording before touching the node', function (string $engine, string $case, Closure $change, string $message) {
    $fixture = goFixture("configure-{$case}.json", $engine);

    expect(fn () => resolve(EngineClient::class)->send(configuredNode($engine, $fixture['line']), configureFromFixture($change($fixture['request']))))
        ->toThrow(InvalidEngineRequest::class, $message);
    expect($this->scripts->ran())->toBe([]);
})->with([
    'mysql admin' => ['mysql', 'standalone-8.4', fn (array $request): array => [...$request, 'secrets' => [...$request['secrets'], 'admin' => null]], 'MySQL needs the admin secret'],
    'mysql agent' => ['mysql', 'primary-9.7', fn (array $request): array => [...$request, 'secrets' => [...$request['secrets'], 'agent' => null]], 'MySQL backups need the agent secret'],
    'mariadb replication' => ['mariadb', 'galera-11.8', fn (array $request): array => [...$request, 'secrets' => [...$request['secrets'], 'replication' => null]], 'MariaDB clusters need the replication secret'],
    'unknown setting' => ['mariadb', 'standalone-10.11', fn (array $request): array => [...$request, 'settings' => ['fsync' => 'off']], 'setting "fsync" is not offered'],
]);

it('applies online settings with SET PERSIST on MySQL and SET GLOBAL on MariaDB', function (string $engine, string $case, array $sets, array $keys, string $forget) {
    $fixture = goFixture("configure-{$case}.json", $engine);
    $ssh = respondToFileGet(fakeSsh(), 'state/node.json', json_encode($fixture['request']));
    respondToFileGet($ssh, 'state/tuned.json', '{"innodb_buffer_pool_size":"2560M","back_log":"256","max_connections":"256"}');

    resolve(EngineClient::class)->send(configuredNode($engine, $fixture['line']), new ConfigApply);

    $this->scripts->assertRan('mysql-family/config/apply', fn (string $script, array $env): bool => json_decode($env['SETS'], true) === $sets
        && json_decode($env['KEYS'], true) === $keys
        && in_array('back_log', json_decode($env['STATIC'], true), true)
        && $env['FORGET'] === $forget);
})->with([
    'mysql' => ['mysql', 'standalone-8.4', ['SET PERSIST innodb_buffer_pool_size = 2684354560', 'SET PERSIST max_connections = 200', "SET PERSIST sql_mode = 'ANSI'"], ['back_log', 'default_time_zone', 'innodb_buffer_pool_size', 'max_connections', 'sql_mode'], 'RESET PERSIST IF EXISTS'],
    'mariadb' => ['mariadb', 'standalone-10.11', ['SET GLOBAL innodb_buffer_pool_size = 2684354560', 'SET GLOBAL max_connections = 200'], ['back_log', 'default_time_zone', 'innodb_buffer_pool_size', 'max_connections'], ''],
]);

it('needs a configured node to apply, show or tune', function (object $request) {
    respondToFileGet(fakeSsh(), 'state/node.json', null);

    expect(fn () => resolve(EngineClient::class)->send(configuredNode('mysql', '8.4'), $request))->toThrow(EngineBusy::class, 'run node configure first');
})->with([
    'apply' => [new ConfigApply],
    'show' => [new ConfigShow],
    'tune' => [new ConfigTune],
]);

it('syncs nothing, as the Go engine does', function () {
    $ssh = fakeSsh();

    resolve(EngineClient::class)->send(configuredNode('mariadb', '12.3'), new ConfigSync);

    expect($this->scripts->ran())->toBe([])->and($ssh->runs)->toBe([]);
});

it('lists restart settings whose live value differs from the wanted one', function () {
    $fixture = goFixture('configure-standalone-8.4.json', 'mysql');
    $ssh = respondToFileGet(fakeSsh(), 'state/node.json', json_encode($fixture['request']));
    respondToFileGet($ssh, 'state/tuned.json', '{"back_log":"256","innodb_read_io_threads":"4","innodb_buffer_pool_size":"2560M"}');
    $this->scripts->respond('mysql-family/config/show', ['values' => ['back_log' => '151', 'innodb_read_io_threads' => '4', 'time_zone' => '+06:00', 'innodb_buffer_pool_size' => '134217728', 'max_connections' => '151']]);

    $show = resolve(EngineClient::class)->send(configuredNode('mysql', '8.4'), new ConfigShow);

    expect($show->pendingRestart)->toBe(['back_log']);
    $this->scripts->assertRan('mysql-family/config/show', fn (string $script, array $env): bool => str_contains($env['NAMES'], "'time_zone'") && ! str_contains($env['NAMES'], "'default_time_zone'"));
});

it('offers tuned defaults once the node is configured', function () {
    $fixture = goFixture('configure-standalone-10.11.json', 'mariadb');
    $ssh = respondToFileGet(fakeSsh(), 'state/node.json', json_encode($fixture['request']));
    respondToFileGet($ssh, 'state/tuned.json', null)->respondToEngine('file put', []);

    $fields = resolve(EngineClient::class)->send(configuredNode('mariadb', '10.11'), new ConfigFields);

    expect(collect($fields->fields)->firstWhere('key', 'innodb_buffer_pool_size')->default)->toBe('2560M');
});

it('retunes from the saved request and reports settings that need a restart', function () {
    $fixture = goFixture('configure-standalone-8.4.json', 'mysql');
    $ssh = respondToFileGet(fakeSsh(), 'state/node.json', json_encode($fixture['request']));
    respondToFileGet($ssh, 'state/tuned.json', '{"back_log":"100","innodb_buffer_pool_size":"128M"}')->respondToEngine('file put', []);

    $tune = resolve(EngineClient::class)->send(configuredNode('mysql', '8.4'), new ConfigTune);

    expect($tune->restart)->toContain('back_log')
        ->and($tune->restart)->not->toContain('max_connections');
    $this->scripts->assertRan('mysql-family/nodes/configure')->assertRan('mysql-family/config/apply');
});
