<?php

declare(strict_types=1);

use App\DatabaseProviders\Drivers\Mysql\Mysql;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\NodeKind;
use App\Enums\Databases\NodeRole;
use App\Enums\Databases\PasswordEncryption;
use App\Jobs\Databases\RetuneDatabaseJob;
use App\Models\Database;
use App\Models\Network;
use App\Models\Server;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

it('marks mysql read replicas by role', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $reader = withReadOnlyNode($database);

    expect(renderedFile($database, 'my.cnf', $reader))->toContain("report_host = {$reader->privateIp()}\n")->toContain('server_id = '.ip2long($reader->privateIp())."\n")
        ->and(Database::providerFor('mysql')->scriptView('cluster.adopt'))->toBe('ssh.engines.mysql.lines.8_4.cluster.adopt')
        ->and(Database::providerFor('mysql')->role("READ_REPLICA ONLINE\n"))->toBe(NodeRole::Replica);
});

it('serves clients straight from mysqld on the client port', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $engine = 'mysql';

    expect(Database::providerFor($engine)->ports()->client)->toBe(25060)
        ->and(Database::providerFor($engine)->ports()->pool)->toBeNull()
        ->and(Database::providerFor($engine)->ports()->peers)->toBe([33061])
        ->and(Database::providerFor($engine)->directory())->toBe('/srv/mysql')
        ->and(Database::providerFor($engine)->systemUser())->toBe('mysql')
        ->and($database->provider()->processes()->toArray()['processes'])->toBe([[
            'name' => 'mysql',
            'user' => 'mysql',
            'env' => ['MYSQLD_PARENT_PID' => '1'],
            'restart' => 'on-failure',
            'restartSec' => '5s',
            'stopTimeout' => '15m',
            'argv' => ['sh', '-c', "[ -d /srv/mysql/data/mysql ] || { echo 'the data directory is not initialised yet, the deploy step initialises it'; exit 0; }; rm -f /srv/mysql/run/mysqld.sock.lock /srv/mysql/run/mysqld.pid && exec mysqld --defaults-file=/srv/mysql/conf/my.cnf"],
            'enabled' => false,
        ]])
        ->and(renderedFile($database, 'my.cnf'))->toContain("port = 25060\n")->toContain("bind_address = \"*\"\n")->toContain("ssl_cert = /srv/mysql/tls/server.crt\n")
        ->and(Database::providerFor($engine)->tls()->files)->toBe(['ca.crt', 'server.crt', 'server.key']);
});

it('gives every member the group replication cache and keeps their own config', function () {
    $database = idleDatabase('mysql', 3);
    $reader = withReadOnlyNode($database);
    [$seed, $standby] = $database->nodes()->members()->get()->all();
    $database->update(['settings' => $database->settings->withConfig(['sql_mode' => 'ANSI'])]);
    $database = $database->fresh() ?? $database;
    $engine = Database::providerFor('mysql');

    expect(renderedFile($database, 'my.cnf', $seed))->toContain("sql_mode = ANSI\n")->toContain('loose-group_replication_message_cache_size = ')
        ->and(renderedFile($database, 'my.cnf', $standby))->toContain("report_host = {$standby->privateIp()}\n")
        ->and(renderedFile($database, 'init.sql', $seed))->toContain("IDENTIFIED BY '{$database->settings->secret('CLUSTER_ADMIN_PASSWORD')}'")
        ->and($database->provider()->membershipVariables())->toBe(['hosts' => $database->nodes()->get()->map->privateIp()->implode(',')])
        ->and($engine->canFailover($database->nodes))->toBeTrue()
        ->and($engine->canFailover($database->nodes->reject(fn ($node) => $node->is($standby))))->toBeFalse()
        ->and($reader->isReadOnly())->toBeTrue();
});

it('gives apps a plain laravel env like digitalocean, with or without a domain', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single, 'db');
    $plain = $database->provider()->environment($database->endpoints());
    $database = withZone($database);
    $trusted = $database->provider()->environment($database->endpoints());

    expect(array_keys($plain))->toBe(['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'])
        ->and($plain)->toMatchArray(['DB_CONNECTION' => 'mysql', 'DB_PORT' => '25060', 'DB_DATABASE' => 'defaultdb', 'DB_USERNAME' => 'databasify'])
        ->and($trusted)->toMatchArray(['DB_HOST' => 'db.db.example.com'])->not->toHaveKey('MYSQL_ATTR_SSL_CA');
});

it('reads innodb database roles', function () {
    $engine = Database::providerFor('mysql');

    expect($engine->role("PRIMARY ONLINE\nversion:8.4.6"))->toBe(NodeRole::Primary)
        ->and($engine->role('SECONDARY ONLINE'))->toBe(NodeRole::Replica)
        ->and($engine->role('SECONDARY RECOVERING'))->toBe(NodeRole::Unknown)
        ->and($engine->role(''))->toBe(NodeRole::Unknown);
});

it('maps operations to mysql scripts', function () {
    $engine = 'mysql';
    $driver = Database::providerFor($engine);

    expect(Database::providerFor($engine)->scriptView('cluster.force-quorum'))->toBe('ssh.engines.mysql.lines.8_4.cluster.force-quorum')
        ->and(Database::providerFor($engine)->scriptView('cluster.quorum'))->toBe('ssh.engines.mysql.lines.8_4.cluster.quorum')
        ->and(Database::providerFor($engine)->scriptView('cluster.forget'))->toBe('ssh.engines.mysql.lines.8_4.cluster.forget')
        ->and(Database::providerFor($engine)->scriptView('lifecycle.start'))->toBe('ssh.engines.mysql.lines.8_4.lifecycle.start')
        ->and(Database::providerFor($engine)->scriptView('lifecycle.wake'))->toBe('ssh.engines.mysql.lines.8_4.lifecycle.wake')
        ->and($driver->scriptVariables())->toBe(['configFile' => '/srv/mysql/conf/my.cnf', 'socket' => '/srv/mysql/run/mysqld.sock', 'runDirectory' => '/srv/mysql/run', 'groupPort' => 33061]);
});

it('creates app credentials and reserves the internal accounts', function () {
    $engine = 'mysql';
    $driver = Database::providerFor($engine);

    expect(array_keys($driver->generateSecrets()))->toBe(['MYSQL_ROOT_PASSWORD', 'CLUSTER_ADMIN_PASSWORD', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'])
        ->and($driver->generateSecrets()['DB_PASSWORD'])->toHaveLength(32)
        ->and($driver->accounts()->reservedUsers())->toContain('root', 'router', 'mysql_innodb_cluster')
        ->and($driver->accounts()->reservedDatabases())->toContain('mysql', 'mysql_innodb_cluster_metadata')
        ->and(Database::providerFor($engine)->accounts()->passwordEncryption())->toBe(PasswordEncryption::Default)
        ->and(Database::providerFor($engine)->accounts())->not->toBeNull();
});

it('keeps its data on disk and explains a manual failover', function () {
    $engine = 'mysql';

    expect(Database::providerFor($engine)->supportsPersistence())->toBeFalse()
        ->and(Database::providerFor($engine)->failoverHint())->toContain('three members');
});

it('tunes mysql through config keys with valid patterns', function () {
    $engine = Database::providerFor('mysql');
    $fields = collect($engine->fields())->keyBy('key');

    expect($fields->keys()->all())->toContain('sql_mode', 'max_connections', 'default_time_zone', 'collation_server')
        ->and($fields['sql_mode']->multiple)->toBeTrue()
        ->and(preg_match('/^'.$fields['default_time_zone']->pattern.'$/', '+06:00'))->toBe(1)
        ->and(preg_match('/^'.$fields['default_time_zone']->pattern.'$/', '+15:00'))->toBe(0)
        ->and($engine->connection()->toArray())->toMatchArray(['uri_scheme' => 'mysql', 'uri_query' => '?ssl-mode=REQUIRED', 'tls_parameter' => 'sslmode', 'tls_mode' => 'REQUIRED', 'command' => 'mysql -u {username} -p{password} -h {host} -P {port} -D {database} --ssl-mode=REQUIRED']);
});

it('connects without a ca by default', function () {
    $profile = Database::providerFor('mysql')->connection();

    expect($profile->command)->toEndWith('--ssl-mode=REQUIRED')->not->toContain('{ca}')
        ->and($profile->uriQuery)->toBe('?ssl-mode=REQUIRED')
        ->and($profile->commandNote)->toContain('MariaDB client, use --ssl --ssl-verify-server-cert=0')
        ->and($profile->envNote)->toContain("Mysql::ATTR_SSL_CIPHER => 'DEFAULT'")->toContain('Mysql::ATTR_SSL_VERIFY_SERVER_CERT => false');
});

it('explains how to verify with the ca', function () {
    $profile = Database::providerFor('mysql')->connection();

    expect($profile->commandNote)->toContain('--ssl-mode=VERIFY_IDENTITY --ssl-ca={ca}')
        ->and($profile->envNote)->toContain('MYSQL_ATTR_SSL_CA=/path/to/{ca}');
});

it('gives the buffer pool a smaller share of memory on servers under 2 GB', function () {
    $database = idleDatabase('mysql', 2);
    [$small, $large] = $database->nodes()->with('server')->get()->all();
    $small->server->update(['memory_mb' => 961]);
    $large->server->update(['memory_mb' => 3925]);

    expect(renderedFile($database, 'my.cnf', $small->fresh()))->toContain("innodb_buffer_pool_size = 256M\n")
        ->and(renderedFile($database, 'my.cnf', $large->fresh()))->toContain("innodb_buffer_pool_size = 2432M\n");
});

it('keeps the option file and socket on the data mount and logs to the wrapper', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);

    expect(Mysql::CONFIG)->toBe('/srv/mysql/conf/my.cnf')
        ->and(renderedFile($database, Mysql::CONFIG))->toContain("[mysqld]\n")->toContain("datadir = /srv/mysql/data\n")->toContain("socket = /srv/mysql/run/mysqld.sock\n")->toContain("pid_file = /srv/mysql/run/mysqld.pid\n")->not->toContain('log_error =')
        ->and(renderedFile($database, 'root.cnf'))->toContain("socket = /srv/mysql/run/mysqld.sock\n")
        ->and(view('ssh.engines.mysql.lifecycle.prepare', $database->provider()->scriptData()->deploy($database->seed()))->render())->toContain('mysqld --defaults-file=/srv/mysql/conf/my.cnf --initialize-insecure --user=mysql');
});

it('talks to mysqlsh with the cluster admin password from its file and no prompt', function () {
    $database = idleDatabase('mysql', 3);
    $helpers = view('ssh.engines.mysql.helpers', $database->provider()->scriptData()->for())->render();
    $create = view('ssh.engines.mysql.cluster.create-cluster', $database->provider()->scriptData()->for($database->seed()))->render();

    expect($helpers)->toContain('mysqlsh --no-defaults --no-wizard')->toContain('--py')->toContain("open('/srv/mysql/conf/clusteradmin.pass').read().strip()")->not->toContain('--passwords-from-stdin')
        ->and($create)->toContain("'communicationStack': 'XCOM'")->toContain("'localAddress': '{$database->seed()->privateIp()}:33061'");
});

it('forgets a node without failing when no cluster metadata exists yet', function () {
    $script = view('ssh.engines.mysql.cluster.forget', engineScript('mysql', ['instance' => '10.0.0.2:25060']))->render();

    expect($script)->toContain("table_schema = 'mysql_innodb_cluster_metadata' AND table_name = 'instances'")
        ->toContain('count=$(msql "$1") || { echo "could not read the cluster metadata"; exit 1; }');
});

it('starts mysqld before rebooting the cluster and forces only past members that do not answer', function () {
    $database = idleDatabase('mysql', 3);
    $script = view('ssh.engines.mysql.cluster.force-quorum', [...$database->provider()->scriptData()->for(), 'instance' => '10.0.0.1:25060'])->render();

    expect(strpos($script, 'databasify ctl start mysql'))->toBeLessThan(strpos($script, 'reboot_cluster_from_complete_outage'))
        ->and($script)->toContain("cluster_admin_at \"\$peer\" \"session.run_sql('SELECT 1')\" 30 > /dev/null 2>&1 || force=True")
        ->toContain("dba.reboot_cluster_from_complete_outage('main', {'force': \$force})");
});

it('leaves no persisted group replication state behind when it runs alone again', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $script = view('ssh.engines.mysql.cluster.uncluster', $database->provider()->scriptData()->clustering($database->seed()))->render();

    expect($script)->toContain('RESET PERSIST IF EXISTS $variable')->toContain("'super_read_only', 'read_only'")
        ->and(strpos($script, 'SET GLOBAL super_read_only = OFF'))->toBeLessThan(strpos($script, 'UNINSTALL PLUGIN group_replication'))
        ->and(strpos($script, 'UNINSTALL PLUGIN group_replication'))->toBeLessThan(strrpos($script, 'record_running_config'));
});

it('rebuilds the group seeds from the cluster metadata before a member restarts', function () {
    $database = idleDatabase('mysql', 3);
    $start = view('ssh.engines.mysql.lifecycle.start', $database->provider()->scriptData()->starting($database->seed(), false))->render();
    $ready = view('ssh.engines.mysql.lifecycle.wait-ready', $database->provider()->scriptData()->readiness($database->seed(), true))->render();

    expect(strpos($start, 'refresh_group_seeds'))->toBeLessThan(strpos($start, 'databasify ctl restart mysql'))
        ->and($start)->toContain("addresses->>'$.grLocal'")->toContain("SET PERSIST group_replication_group_seeds = '\$seeds'")
        ->and($ready)->toContain('mysql runs but stayed outside the group');
});

it('clones a member again when its data directory carries another node identity', function (NodeKind $kind, string $rejoin) {
    $database = idleDatabase('mysql', 3);
    $node = $database->nodes()->where('position', '>', $database->seed()->position)->firstOrFail();
    $node->update(['kind' => $kind]);
    $start = view('ssh.engines.mysql.lifecycle.start', $database->provider()->scriptData()->starting($node, false))->render();

    expect(strpos($start, 'wait_for 900 up'))->toBeLessThan(strpos($start, 'if in_cluster && copied_identity; then'))
        ->and($start)->toContain('mysql_server_uuid <> @@server_uuid')
        ->toContain("cluster.remove_instance('\$instance', {'force': True}); ".str_replace('{ip}', $node->privateIp(), $rejoin));
})->with([
    'member' => [NodeKind::Member, "cluster.add_instance('\$instance', {'recoveryMethod': 'clone', 'localAddress': '{ip}:33061'\$(group_allowlist_option)})"],
    'read-only node' => [NodeKind::ReadOnly, "cluster.add_replica_instance('\$instance', {'recoveryMethod': 'clone'})"],
]);

it('stops waiting for a member whose group replication never initialised or is not installed', function () {
    $database = idleDatabase('mysql', 3);
    $ready = view('ssh.engines.mysql.lifecycle.wait-ready', $database->provider()->scriptData()->readiness($database->seed(), true))->render();

    expect($ready)->toContain("WHERE MEMBER_ID IN (@@server_uuid, '')")->toContain("OFFLINE|ERROR|'') ;;");
});

it('gives a data directory copied from another node a new server_uuid, but keeps the uuid of a node that owns its data', function (?string $marker, bool $keepsUuid) {
    $database = idleDatabase('mysql', 2);
    [, $standby] = $database->nodes()->get()->all();
    $nodeKey = "{$database->dataKey()}-{$standby->id}";
    $dir = sys_get_temp_dir().'/mysql-prepare-'.uniqid();
    mkdir("{$dir}/data/mysql", recursive: true);
    file_put_contents("{$dir}/data/auto.cnf", "[auto]\nserver-uuid=donor\n");
    if ($marker !== null) {
        file_put_contents("{$dir}/data/.databasify-node", $marker === 'self' ? $nodeKey : 'other-database-7');
    }
    $script = str_replace('dir=/srv/mysql', "dir={$dir}", view('ssh.engines.mysql.lifecycle.prepare', $database->provider()->scriptData()->deploy($standby))->render());

    Process::input("install() { :; }\nchown() { :; }\n{$script}")->run(['bash'])->throw();

    expect(file_exists("{$dir}/data/auto.cnf"))->toBe($keepsUuid)
        ->and(trim((string) file_get_contents("{$dir}/data/.databasify-node")))->toBe($nodeKey);
    File::deleteDirectory($dir);
})->with([
    'a node deployed before markers existed' => [null, true],
    'the node that owns the data' => ['self', true],
    'data copied from another node' => ['other', false],
]);

it('keeps binary logs for three days and fences an isolated primary after 30 seconds', function () {
    $single = idleDatabase('mysql', 1, DatabaseMode::Single, 'solo');
    $cluster = idleDatabase('mysql', 3, name: 'trio');

    expect(renderedFile($single, 'my.cnf', $single->seed()))->toContain("binlog_expire_logs_seconds = 259200\n")->not->toContain('unreachable_majority_timeout')
        ->and(renderedFile($cluster, 'my.cnf', $cluster->seed()))->toContain("loose-group_replication_unreachable_majority_timeout = 30\n");
});

it('allows group replication traffic from the whole private network, which may be outside the automatic private ranges', function () {
    $database = idleDatabase('mysql', 3);
    $network = Network::factory()->wireguard()->create();
    Server::query()->whereKey($database->nodes()->pluck('server_id'))->update(['network_id' => $network->id]);
    $seed = $database->seed()->fresh() ?? $database->seed();
    $myCnf = renderedFile($database, 'my.cnf', $seed);
    $script = view('ssh.engines.mysql.helpers', $database->provider()->scriptData()->for($seed))->render();
    $file = tempnam(sys_get_temp_dir(), 'my.cnf');
    file_put_contents($file, $myCnf);

    $option = Process::input(str_replace(Mysql::CONFIG, $file, $script)."\ngroup_allowlist_option\n")->run(['bash'])->output();
    File::delete($file);

    expect($myCnf)->toContain("loose-group_replication_ip_allowlist = {$network->cidr},127.0.0.1/8\n")
        ->and($option)->toBe(", 'ipAllowlist': '{$network->cidr},127.0.0.1/8'\n")
        ->and(view('ssh.engines.mysql.cluster.create-cluster', $database->provider()->scriptData()->for($seed))->render())->toContain(":33061'\$(group_allowlist_option)})");
});

it('drives mysqlsh in python mode, which every mysql-shell build ships, unlike javascript', function () {
    $scripts = collect(File::allFiles(resource_path('views/ssh/engines/mysql')))
        ->map(fn (SplFileInfo $file): string => (string) file_get_contents($file->getPathname()))
        ->implode("\n");

    expect($scripts)->not->toContain('--js')->not->toContain('dba.getCluster')->not->toContain('{force: true}')->not->toContain('util.dumpSchemas')->not->toContain('println(');
});

it('clones a member back in when it lost its data but the cluster still lists it', function () {
    $database = idleDatabase('mysql', 3);
    $node = $database->nodes()->where('position', '>', $database->seed()->position)->firstOrFail();
    $start = view('ssh.engines.mysql.lifecycle.start', $database->provider()->scriptData()->starting($node, false))->render();
    $bootstrap = view('ssh.engines.mysql.lifecycle.start', $database->provider()->scriptData()->starting($database->seed(), true))->render();

    expect($start)->toContain("elif ! in_cluster; then\n")
        ->toContain("peer='{$database->primaryOrSeed()->privateIp()}'")
        ->toContain('listed_at "$peer" "$instance"')
        ->toContain("cluster.add_instance('\$instance', {'recoveryMethod': 'clone', 'localAddress': '{$node->privateIp()}:33061'\$(group_allowlist_option)})")
        ->and(strpos($start, 'listed_at "$peer"'))->toBeLessThan(strrpos($start, "cluster.remove_instance('\$instance'"))
        ->and($bootstrap)->not->toContain('elif ! in_cluster');
});

it('keeps mysqld running when a redeploy changes neither its config nor its binaries', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $start = view('ssh.engines.mysql.lifecycle.start', $database->provider()->scriptData()->starting($database->seed(), false))->render();
    $apply = view('ssh.engines.mysql.config.apply-config', $database->provider()->scriptData()->liveConfig($database->seed(), ['max_execution_time']))->render();

    expect($start)->toContain("if runs_current_config && in_the_group; then\n    echo \"mysql already runs this configuration, no restart needed\"")
        ->toContain("if runs_current_config; then\n    wait_for 300 settled || true\nfi")
        ->toContain("in_the_group() { own_state | grep -q -E 'ONLINE|RECOVERING'; }")
        ->toContain("WHERE VARIABLE_NAME = 'Uptime'\" || echo 0)\" -lt 60 ]; }")->not->toContain('RELOAD TLS')
        ->toContain("config_fingerprint() { { cat /srv/mysql/conf/my.cnf; jq -c '.processes' /srv/mysql/conf/processes.json || true; }")
        ->and(strpos($start, 'databasify ctl restart mysql'))->toBeLessThan(strpos($start, "    record_running_config\nfi"))
        ->and($apply)->toContain("record_running_config\necho \"settings applied\"");
});

it('lets an older primary leave the group when the upgraded standby cannot be appointed over it', function () {
    $database = idleDatabase('mysql', 3);
    $script = view('ssh.engines.mysql.cluster.failover', [...$database->provider()->scriptData()->for(), 'target' => '10.0.0.2:25060'])->render();

    expect($script)->toContain("WHERE target.MEMBER_HOST = '10.0.0.2' AND target.MEMBER_VERSION <> own.MEMBER_VERSION")
        ->toContain("    msql \"STOP GROUP_REPLICATION\" > /dev/null\n")
        ->toContain("    cluster_admin \"dba.get_cluster().set_primary_instance('10.0.0.2:25060')\" > /dev/null\n")
        ->and(strpos($script, 'STOP GROUP_REPLICATION'))->toBeLessThan(strpos($script, 'set_primary_instance'))
        ->and(bashErrorsIn($script))->toBe('');
});

it('runs every mysql step through the wrapper instead of systemd', function () {
    $scripts = collect(File::allFiles(resource_path('views/ssh/engines/mysql')))
        ->map(fn (SplFileInfo $file): string => (string) file_get_contents($file->getPathname()))
        ->implode("\n");

    expect($scripts)->not->toContain('systemctl')->not->toContain('journalctl')->not->toContain('systemd-run')->not->toContain('/etc/mysql');
});

it('fails the readiness wait when mysqld keeps crashing under the wrapper', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $ready = view('ssh.engines.mysql.lifecycle.wait-ready', $database->provider()->scriptData()->readiness($database->seed(), true))->render();

    expect($ready)->toContain('crashed() { databasify ctl is-failed --quiet mysql || [ "$(restarts)" -ge $((restarts_before + 2)) ]; }')
        ->toContain('databasify ctl logs -u mysql -n 20')
        ->and(bashErrorsIn($ready))->toBe('');
});

it('archives binlogs and takes base backups on schedules inside the container only while recovery is on', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $engine = 'mysql';
    $without = $database->provider()->processes()->toArray();
    $database = withRecovery($database, ['backup_hour' => 4]);

    expect($without)->not->toHaveKey('schedules')
        ->and($database->provider()->processes()->toArray()['schedules'])->toBe([
            ['name' => 'binlog-archive', 'every' => '1m', 'timeout' => '1h', 'argv' => ['/srv/mysql/archive/bin/archive']],
            ['name' => 'binlog-backup', 'onCalendar' => '04:00', 'timeout' => '24h', 'argv' => ['/srv/mysql/archive/bin/backup']],
        ])
        ->and(Database::providerFor($engine)->scriptView('archive.status'))->toBe('ssh.engines.mysql.lines.8_4.archive.status')
        ->and(Database::providerFor($engine)->scriptView('archive.restore'))->toBe('ssh.engines.mysql.lines.8_4.archive.restore');
});

it('stays primary while a migration channel replicates into it', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $status = view(Database::providerFor('mysql')->scriptView('lifecycle.status'), $database->provider()->scriptData()->for($database->seed()))->render();

    expect($status)->toContain("WHERE CHANNEL_NAME NOT LIKE 'group_replication%' AND CHANNEL_NAME <> 'migration' LIMIT 1");
});

it('clones a new read-only node from a healthy standby when there is one', function () {
    $database = idleDatabase('mysql', 2);
    $reader = withReadOnlyNode($database);
    $alone = view('ssh.engines.mysql.cluster.adopt', $database->provider()->scriptData()->admission($reader))->render();
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    $standby = $database->nodes()->members()->get()[1];

    expect($alone)->not->toContain('cloneDonor')
        ->and(view('ssh.engines.mysql.cluster.adopt', $database->fresh()->provider()->scriptData()->admission($reader))->render())->toContain("'cloneDonor': '{$standby->privateIp()}:25060'");
});

it('leaves mysql_native_password out of the mysql 9.7 server config, since 9.0 removed that option and mysqld would refuse to start', function () {
    $legacy = idleDatabase('mysql', 1, DatabaseMode::Single);
    $current = idleDatabase('mysql', 3, name: 'current', versionLine: '9.7');

    expect(renderedFile($legacy, 'my.cnf'))->toContain("mysql_native_password = ON\n")
        ->and(renderedFile($current, 'my.cnf'))->not->toContain('mysql_native_password')
        ->toContain("gtid_mode = ON\n")->toContain("plugin_load_add = mysql_clone.so\n")->toContain('loose-group_replication_message_cache_size = ')
        ->and($current->provider()->scriptView('files.server'))->toBe('ssh.engines.mysql.lines.9_7.files.server')
        ->and($current->provider()->scriptView('lifecycle.start'))->toBe('ssh.engines.mysql.lines.9_7.lifecycle.start')
        ->and($current->provider()->versions()->firstWhere('value', '9.7'))->label->toBe('MySQL 9.7 LTS')->isDefault->toBeFalse()->archives->toBeTrue();
});

it('runs mysql 9.7 group replication over the client port on the mysql stack, since 9.7 deprecates xcom and the ip allowlist', function () {
    $database = idleDatabase('mysql', 3, name: 'current', versionLine: '9.7');
    $network = Network::factory()->wireguard()->create();
    Server::query()->whereKey($database->nodes()->pluck('server_id'))->update(['network_id' => $network->id]);
    $seed = $database->seed()->fresh() ?? $database->seed();
    $node = $database->nodes()->where('position', '>', $seed->position)->firstOrFail();
    $engine = $database->provider();
    $scripts = collect([
        view($engine->scriptView('cluster.cluster'), $engine->scriptData()->for($seed))->render(),
        view($engine->scriptView('lifecycle.start'), $engine->scriptData()->starting($seed, true))->render(),
        view($engine->scriptView('lifecycle.start'), $engine->scriptData()->starting($node, false))->render(),
        view($engine->scriptView('cluster.adopt'), $engine->scriptData()->admission($node))->render(),
    ]);

    expect(renderedFile($database, 'my.cnf', $seed))->not->toContain('allowlist')
        ->and($scripts[0])->toContain("dba.create_cluster('main', {'communicationStack': 'MYSQL', 'memberSslMode': 'REQUIRED', 'expelTimeout': 0})")
        ->and($scripts[1])->toContain("'communicationStack': 'MYSQL'")
        ->and($scripts[2])->toContain("cluster.add_instance('\$instance', {'recoveryMethod': 'clone'})")
        ->and($scripts[3])->toContain("add_instance('{$node->privateIp()}:25060', {'recoveryMethod': 'clone'})")
        ->and($scripts->implode("\n"))->not->toContain('XCOM')->not->toContain('localAddress')->not->toContain('$(group_allowlist_option)');
});

it('offers the change buffer default of each line, which mysql 9.7 lowered to 5 percent', function () {
    $legacy = idleDatabase('mysql', 1, DatabaseMode::Single);
    $current = idleDatabase('mysql', 1, DatabaseMode::Single, name: 'current', versionLine: '9.7');

    expect(collect($legacy->provider()->fields())->firstWhere('key', 'innodb_change_buffer_max_size')->default)->toBe('25')
        ->and(collect($current->provider()->fields())->firstWhere('key', 'innodb_change_buffer_max_size')->default)->toBe('5');
});

it('offers the change buffer default of the default line without a database', function () {
    $current = idleDatabase('mysql', 1, DatabaseMode::Single, versionLine: '9.7');

    expect(collect(Database::providerFor('mysql')->fields())->firstWhere('key', 'innodb_change_buffer_max_size')->default)->toBe('25')
        ->and(collect($current->provider()->fields())->firstWhere('key', 'innodb_change_buffer_max_size')->default)->toBe('5');
});

it('offers the password encryptions of the default line without a database', function () {
    expect(Database::providerFor('mysql')->accounts()?->passwordEncryptions())->toBe(PasswordEncryption::cases());
});

it('retunes without switching the primary when no setting needs a restart', function () {
    $database = withCurrentCertificates(idleDatabase('mysql', 3));
    $primary = $database->seed();
    $database->startOperation('Retune for server size');
    $facts = '{"os":"Ubuntu 24.04","id":"ubuntu","version_id":"24.04","cpus":2,"memory_mb":4096,"disk_gb":80}'."\n";
    $ssh = fakeSsh()->respond($facts)->respond($facts)->respond($facts)
        ->respond("PRIMARY ONLINE\n")->respond("SECONDARY ONLINE\n")->respond("SECONDARY ONLINE\n");
    foreach (range(1, 8) as $ignored) {
        $ssh->respond();
    }
    $ssh->respond("image={$database->image()->reference()}\n")->respond()->respond("runs the current configuration\n")->respond()->respond()->respond()->respond()
        ->respond("PRIMARY ONLINE\n")->respond("SECONDARY ONLINE\n")->respond("SECONDARY ONLINE\n");

    runJob(new RetuneDatabaseJob($database));

    expect(collect($ssh->runs)->pluck('script')->filter(fn (string $script): bool => str_contains($script, 'set_primary_instance')))->toBeEmpty()
        ->and($ssh->runs[14]['server'])->toBe($primary->server->name)
        ->and($ssh->scriptAt(14))->toContain('echo "image=$(running_image')->not->toContain('deployed configuration')
        ->and($ssh->scriptAt(15))->toContain("image='{$database->image()->reference()}'")
        ->and($ssh->scriptAt(16))->toContain('databasify step mysql.lines.8_4.lifecycle.runs-current-config')->toContain("if runs_current_config && in_the_group; then\n")
        ->and($ssh->runs[19]['server'])->toBe($primary->server->name)
        ->and($ssh->scriptAt(19))->toContain('mysql already runs this configuration, no restart needed')
        ->and($database->fresh()->operation)->toBeNull();
});

it('switches the primary off before a retune restarts it', function () {
    $database = withCurrentCertificates(idleDatabase('mysql', 3));
    $database->startOperation('Retune for server size');
    $facts = '{"os":"Ubuntu 24.04","id":"ubuntu","version_id":"24.04","cpus":2,"memory_mb":4096,"disk_gb":80}'."\n";
    $ssh = fakeSsh()->respond($facts)->respond($facts)->respond($facts)
        ->respond("PRIMARY ONLINE\n")->respond("SECONDARY ONLINE\n")->respond("SECONDARY ONLINE\n");
    foreach (range(1, 8) as $ignored) {
        $ssh->respond();
    }
    $ssh->respond("image={$database->image()->reference()}\n")->respond()->respond()
        ->respond("PRIMARY ONLINE\n")->respond("SECONDARY ONLINE\n")->respond("SECONDARY ONLINE\n")->respond()
        ->respond("SECONDARY ONLINE\n")->respond("PRIMARY ONLINE\n")->respond("SECONDARY ONLINE\n");

    runJob(new RetuneDatabaseJob($database));

    expect($ssh->scriptAt(16))->toContain('lifecycle.runs-current-config')
        ->and($ssh->scriptAt(20))->toContain('set_primary_instance')
        ->and($database->fresh()->operation)->toBeNull();
});

it('switches the primary off before configuring it when its container runs another image', function () {
    $database = withCurrentCertificates(idleDatabase('mysql', 3));
    $primary = $database->seed();
    $database->startOperation('Retune for server size');
    $facts = '{"os":"Ubuntu 24.04","id":"ubuntu","version_id":"24.04","cpus":2,"memory_mb":4096,"disk_gb":80}'."\n";
    $ssh = fakeSsh()->respond($facts)->respond($facts)->respond($facts)
        ->respond("PRIMARY ONLINE\n")->respond("SECONDARY ONLINE\n")->respond("SECONDARY ONLINE\n");
    foreach (range(1, 8) as $ignored) {
        $ssh->respond();
    }
    $ssh->respond('image=ghcr.io/databasifylabs/databasify-mysql@sha256:'.str_repeat('e', 64)."\n")
        ->respond("PRIMARY ONLINE\n")->respond("SECONDARY ONLINE\n")->respond("SECONDARY ONLINE\n")->respond()
        ->respond("SECONDARY ONLINE\n")->respond("PRIMARY ONLINE\n")->respond("SECONDARY ONLINE\n");

    runJob(new RetuneDatabaseJob($database));

    $onPrimaryBeforeFailover = collect($ssh->runs)->slice(14, 4)->filter(fn (array $run): bool => $run['server'] === $primary->server->name)->pluck('script');

    expect($ssh->scriptAt(18))->toContain('set_primary_instance')
        ->and($onPrimaryBeforeFailover->filter(fn (string $script): bool => str_contains($script, 'deployed configuration') || str_contains($script, 'runs-current-config')))->toBeEmpty()
        ->and($database->fresh()->operation)->toBeNull();
});
