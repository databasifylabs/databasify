<?php

declare(strict_types=1);

use App\DatabaseProviders\Drivers\Mariadb\Mariadb;
use App\DTOs\Migrations\MigrationSource;
use App\DTOs\Recovery\RecoverySource;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\NodeRole;
use App\Jobs\Databases\RetuneDatabaseJob;
use App\Models\Database;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

afterEach(function () {
    foreach (File::glob(mariadbStubsRoot().'*') as $directory) {
        File::deleteDirectory($directory);
    }
});

it('runs mariadb as a galera database with read-only replicas', function () {
    $database = idleDatabase('mariadb', 3, name: 'ledger');
    $engine = Database::providerFor('mariadb');
    $reader = withReadOnlyNode($database);
    [$seed] = $database->nodes()->members()->get()->all();
    $members = $database->nodes()->members()->get()->map->privateIp()->implode(',');

    expect($database->settings->secret('SST_PASSWORD'))->toHaveLength(32)
        ->and(renderedFile($database, '99-databasify.cnf', $seed))->toContain("wsrep_on = ON\n")->toContain("wsrep_cluster_address = gcomm://{$members}\n")->toContain("wsrep_sst_auth = sst:{$database->settings->secret('SST_PASSWORD')}\n")->toContain('wsrep_provider_options = "gcache.size=')->toContain("read_only = ON\n")
        ->and(renderedFile($database, '99-databasify.cnf', $reader))->toContain("wsrep_on = OFF\nread_only = ON\nrelay_log = relay-bin\n")
        ->and($database->provider()->environment($database->endpoints()))->toMatchArray(['DB_CONNECTION' => 'mariadb', 'DB_PORT' => '25060'])
        ->and($database->provider()->ports()->peers)->toBe([4444, 4567, 4568])
        ->and($engine->canFailover($database->nodes))->toBeTrue();
});

it('reports the single writer as primary', function () {
    $engine = Database::providerFor('mariadb');

    expect($engine->role("WRITER ONLINE\nversion:11.8.9"))->toBe(NodeRole::Primary)
        ->and($engine->role('MEMBER ONLINE'))->toBe(NodeRole::Replica)
        ->and($engine->role('READ_REPLICA ONLINE'))->toBe(NodeRole::Replica)
        ->and($engine->role('UNKNOWN OFFLINE'))->toBe(NodeRole::Unknown);
});

it('moves the writer by recording the preferred member', function () {
    $database = idleDatabase('mariadb', 3);
    $target = $database->nodes()->members()->get()[1];
    $script = view('ssh.engines.mariadb.cluster.failover', [...$database->provider()->scriptData()->for(), 'target' => Database::providerFor('mariadb')->clientAddress($target)])->render();

    expect($script)->toContain("REPLACE INTO databasify.writer VALUES (1, '\$target')")->toContain("target={$target->privateIp()}")->toContain('SET GLOBAL read_only = 1');
});

it('streams the clone of a read-only node only to that node over ipv4', function () {
    $database = idleDatabase('mariadb', 2);
    $reader = withReadOnlyNode($database);
    $script = view('ssh.engines.mariadb.cluster.admit', $database->provider()->scriptData()->admission($reader))->render();

    expect($script)->toContain("TCP4-LISTEN:4444,bind={$database->seed()->privateIp()},range={$reader->privateIp()}/32");
});

it('repoints a read-only node at the current primary and records the elected writer', function () {
    $database = idleDatabase('mariadb', 2);
    $reader = withReadOnlyNode($database);
    $primary = $database->nodes()->members()->get()[1];
    $primary->update(['role' => NodeRole::Primary]);
    $script = view('ssh.engines.mariadb.lifecycle.status', $database->provider()->scriptData()->for($reader))->render();

    expect($script)->toContain("[ \"\$(replication_source)\" != '{$primary->privateIp()}' ]")
        ->toContain("CHANGE MASTER TO MASTER_HOST = '{$primary->privateIp()}'")
        ->toContain("REPLACE INTO databasify.writer VALUES (1, '\$me')")
        ->and(view('ssh.engines.mariadb.helpers', engineScript('mariadb'))->render())->toContain('replicating() { replicating_on \'\'; }')->not->toContain('information_schema.SLAVE_STATUS');
});

it('fences a member that lost the primary component so it rejoins read-only', function () {
    $database = idleDatabase('mariadb', 2);
    $script = view('ssh.engines.mariadb.lifecycle.status', $database->provider()->scriptData()->for($database->seed()))->render();

    expect($script)->toContain('elif in_galera && [ "$(global_status WSREP_CLUSTER_STATUS)" != "Primary" ]; then')
        ->toContain('|| msql "SET GLOBAL read_only = 1" || true');
});

it('lets only a serving galera member take writes', function (string $localState, string $state, bool $writable) {
    $database = idleDatabase('mariadb', 2);
    $script = view('ssh.engines.mariadb.lines.11_8.lifecycle.status', $database->provider()->scriptData()->for($database->seed()))->render();
    $bin = mariadbStubs([
        'mariadb' => <<<'BASH'
            while [ $# -gt 0 ]; do [ "$1" = -e ] && query=$2; shift; done
            echo "$query" >> "$STUB_DIR/queries"
            case "$query" in
                *"'WSREP_LOCAL_STATE'"*) echo "$WSREP_STATE" ;;
                *"'WSREP_CLUSTER_STATUS'"*) echo Primary ;;
                *"'WSREP_READY'"*) echo ON ;;
                *"'WSREP_INCOMING_ADDRESSES'"*) echo 10.0.0.1:25060,10.0.0.2:25060 ;;
                *wsrep_on*) echo 1 ;;
                *wsrep_node_address*) echo 10.0.0.1 ;;
                *read_only*) echo 1 ;;
                *VERSION*) echo 11.8.9-MariaDB ;;
            esac
            BASH,
    ]);

    $result = Process::env(['PATH' => "{$bin}:".getenv('PATH'), 'STUB_DIR' => $bin, 'WSREP_STATE' => $localState])->input($script)->run(['bash']);

    expect($result->output())->toContain("{$state}\nversion:11.8.9")
        ->and(str_contains((string) file_get_contents("{$bin}/queries"), 'SET GLOBAL read_only = 0'))->toBe($writable);
})->with([
    'a donor' => ['2', 'WRITER ONLINE', true],
    'a synced member' => ['4', 'WRITER ONLINE', true],
    'a joiner' => ['1', 'UNKNOWN OFFLINE', false],
    'a joined member catching up' => ['3', 'UNKNOWN OFFLINE', false],
]);

it('reads a read-only replica from show slave status, which mariadb 11.4 has without information_schema.SLAVE_STATUS', function (string $source, bool $repointed) {
    $database = idleDatabase('mariadb', 1, DatabaseMode::Single, versionLine: '11.4');
    $reader = withReadOnlyNode($database);
    $script = view('ssh.engines.mariadb.lines.11_4.lifecycle.status', $database->provider()->scriptData()->for($reader))->render();
    preg_match("/replication_source\)\" != '([^']+)'/", $script, $primary);
    $bin = mariadbStubs([
        'mariadb' => <<<'BASH'
            while [ $# -gt 0 ]; do [ "$1" = -e ] && query=$2; shift; done
            echo "$query" >> "$STUB_DIR/queries"
            case "$query" in
                *SLAVE_STATUS*) exit 1 ;;
                "SHOW SLAVE '' STATUS\G") printf '        Master_Host: %s\n   Slave_IO_Running: Yes\n  Slave_SQL_Running: Yes\n' "$MASTER_HOST" ;;
                *wsrep_on*) echo 0 ;;
                *read_only*) echo 1 ;;
                *VERSION*) echo 11.4.13-MariaDB ;;
            esac
            BASH,
    ]);

    $result = Process::env(['PATH' => "{$bin}:".getenv('PATH'), 'STUB_DIR' => $bin, 'MASTER_HOST' => $source === 'primary' ? $primary[1] : $source])->input($script)->run(['bash']);

    expect($result->output())->toContain("READ_REPLICA ONLINE\nversion:11.4.13")
        ->and(str_contains((string) file_get_contents("{$bin}/queries"), "CHANGE MASTER TO MASTER_HOST = '{$primary[1]}'"))->toBe($repointed);
})->with([
    'following the primary' => ['primary', false],
    'following an old primary' => ['10.9.9.9', true],
]);

it('keeps its version line and reserves the internal accounts', function () {
    $database = idleDatabase('mariadb', 1, DatabaseMode::Single, versionLine: '11.4');

    $this->get("/databases/{$database->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('database.data.version_line', '11.4')
        ->where('database.data.supports_users', true));
    $this->post("/databases/{$database->id}/users", ['name' => 'sst'])->assertSessionHasErrors('name');
    $this->post("/databases/{$database->id}/schemas", ['name' => 'databasify'])->assertSessionHasErrors('name');
});

it('needs row binlogs on the source to keep a mariadb migration in sync', function () {
    $database = idleDatabase('mariadb', 1, DatabaseMode::Single);
    fakeSsh()
        ->respond("reachable=yes\nversion=10.11.6-MariaDB\nlog_bin=0\nbinlog_format=MIXED\ngrants=GRANT USAGE ON *.* TO `m`@`%`\nsize_mb=40\n")
        ->respond("reachable=yes\nversion=11.4.2-MariaDB\nlog_bin=1\nbinlog_format=ROW\ngrants=GRANT REPLICATION SLAVE, BINLOG MONITOR, RELOAD ON *.* TO `m`@`%`\nsize_mb=40\n");
    $source = new MigrationSource('db.db.example.com', 3306, 'm', 'secret', true, []);
    $migration = $database->provider()->migration();

    expect($migration->check($source)->blockers)->toHaveCount(4)
        ->and($migration->check($source)->supportsContinuous())->toBeTrue();
});

it('keeps mariadb system variables intact in the rendered scripts', function () {
    $database = idleDatabase('mariadb', 1, DatabaseMode::Single);

    expect(view('ssh.engines.mariadb.lifecycle.status', $database->provider()->scriptData()->for($database->seed()))->render())->toContain("msql 'SELECT @@wsrep_on'")->toContain("msql 'SELECT @@read_only'")->not->toContain("SELECT @wsrep_on'")->toContain('SET GLOBAL read_only = $want')
        ->and(view('ssh.engines.mariadb.migrations.check', ['stateDir' => '/tmp', 'host' => 'db', 'port' => 3306, 'username' => 'u', 'password' => 'p', 'tls' => true])->render())->toContain('SELECT @@log_bin');
});

it('drops the galera cache once the last member runs on its own', function () {
    $database = idleDatabase('mariadb', 1, DatabaseMode::Single);
    $script = view('ssh.engines.mariadb.cluster.uncluster', $database->provider()->scriptData()->clustering($database->seed()))->render();

    expect($script)->toContain('rm -f "$dir"/data/galera.cache "$dir"/data/grastate.dat');
});

it('restarts only a node that still runs galera when clustering is turned off', function () {
    $database = idleDatabase('mariadb', 1, DatabaseMode::Single);
    $script = view('ssh.engines.mariadb.cluster.uncluster', $database->provider()->scriptData()->clustering($database->seed()))->render();

    expect($script)->toContain("if in_galera; then\n    databasify ctl restart mariadb\nelse\n    restart_unless_current\nfi");
});

it('forces quorum on a running member through the provider plugin variable that mariadb 11 requires', function () {
    $database = idleDatabase('mariadb', 3);
    $script = view('ssh.engines.mariadb.cluster.force-quorum', [...$database->provider()->scriptData()->for($database->seed()), 'instance' => '10.0.0.1:25060'])->render();

    expect($script)->toContain('msql "SET GLOBAL wsrep_provider_pc_bootstrap = ON" || msql "SET GLOBAL wsrep_provider_options = \'pc.bootstrap=YES\'"')
        ->toContain("msql 'SELECT @@wsrep_on'")
        ->toContain('galera_new_cluster');
});

it('hands the writer to the member it forced quorum on', function () {
    $database = idleDatabase('mariadb', 3);
    $script = view('ssh.engines.mariadb.cluster.force-quorum', [...$database->provider()->scriptData()->for($database->seed()), 'instance' => '10.0.0.1:25060'])->render();

    expect($script)->toContain("REPLACE INTO databasify.writer VALUES (1, '$(msql 'SELECT @@wsrep_node_address')')")
        ->toContain('msql "SET GLOBAL read_only = 0"');
});

it('removes its container, image, data and host tuning when a node is erased', function () {
    withImage('mariadb', '11.8.9');
    $database = idleDatabase('mariadb', 1, DatabaseMode::Single);
    $script = view('ssh.databases.nodes.stop', $database->provider()->scriptData()->stopping(true))->render();

    expect($script)->toContain("docker stop databasify-mariadb > /dev/null\n")
        ->toContain('docker rm -v -f databasify-mariadb')
        ->toContain("awk -v repository='ghcr.io/databasifylabs/databasify-mariadb' '\$1 == repository { print \$2 }'")
        ->toContain('rm -f /etc/sysctl.d/99-mysql.conf')
        ->toContain('sysctl -q -p "$originals"')
        ->toContain("rm -rf \"\$dir\"\necho \"stopped and erased \$dir\"")
        ->not->toContain('apt-get')
        ->and(bashErrorsIn($script))->toBe('');
});

it('serves clients straight from mariadb on the client port', function () {
    $database = idleDatabase('mariadb', 1, DatabaseMode::Single);
    $engine = 'mariadb';
    $driver = Database::providerFor($engine);

    expect(Database::providerFor($engine)->ports()->client)->toBe(25060)
        ->and(Database::providerFor($engine)->ports()->pool)->toBeNull()
        ->and(Database::providerFor($engine)->directory())->toBe('/srv/mariadb')
        ->and(Database::providerFor($engine)->systemUser())->toBe('mysql')
        ->and($database->provider()->processes()->toArray()['processes'][0])->toMatchArray(['name' => 'mariadb', 'user' => 'mysql', 'restart' => 'on-failure', 'stopTimeout' => '15m'])
        ->and(fileWrittenIn(view('ssh.databases.nodes.deploy', $database->provider()->scriptData()->deploy($database->seed()))->render(), '/srv/mariadb/conf/99-databasify.cnf'))->toContain("port = 25060\n")
        ->and(renderedFile($database, '99-databasify.cnf'))->toContain("port = 25060\n")->toContain("bind_address = \"*\"\n")->not->toContain('wsrep_on')->not->toContain('read_only')
        ->and($driver->clientAddress($database->seed()))->toBe($database->seed()->privateIp().':25060');
});

it('lists only members in the galera cluster address', function () {
    $database = idleDatabase('mariadb', 2);
    withReadOnlyNode($database);
    [$seed, $standby] = $database->nodes()->members()->get()->all();
    $engine = Database::providerFor('mariadb');

    expect(renderedFile($database, '99-databasify.cnf', $standby))->toContain("wsrep_cluster_address = gcomm://{$seed->privateIp()},{$standby->privateIp()}\n")->toContain("wsrep_node_address = {$standby->privateIp()}\n")->toContain("wsrep_node_incoming_address = {$standby->privateIp()}:25060\n")->toContain("bind_address = \"*\"\n")
        ->and($database->provider()->membershipVariables())->toBe(['hosts' => $seed->privateIp().','.$standby->privateIp(), 'configPath' => Mariadb::CONFIG])
        ->and($engine->canFailover($database->nodes))->toBeFalse();
});

it('gives apps a plain laravel env like digitalocean, with or without a domain', function () {
    $database = idleDatabase('mariadb', 1, DatabaseMode::Single, 'ledger');
    $plain = $database->provider()->environment($database->endpoints());
    $database = withZone($database);

    expect(array_keys($plain))->toBe(['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'])
        ->and($database->provider()->environment($database->endpoints()))->toMatchArray(['DB_CONNECTION' => 'mariadb', 'DB_HOST' => 'ledger.db.example.com'])->not->toHaveKey('MYSQL_ATTR_SSL_CA');
});

it('maps operations to mariadb scripts and reuses the mysql host tuning', function () {
    $engine = 'mariadb';
    $driver = Database::providerFor($engine);

    expect(Database::providerFor($engine)->scriptView('config.host-tuning'))->toBe('ssh.engines.mariadb.lines.11_8.config.host-tuning')
        ->and(view(Database::providerFor($engine)->scriptView('config.host-tuning'))->render())->toBe(view('ssh.engines.mysql.config.host-tuning')->render())
        ->and(Database::providerFor($engine)->scriptView('cluster.failover'))->toBe('ssh.engines.mariadb.lines.11_8.cluster.failover')
        ->and(Database::providerFor($engine)->scriptView('accounts.run-sql'))->toBe('ssh.engines.mariadb.lines.11_8.accounts.run-sql')
        ->and($driver->scriptVariables())->toBe(['configFile' => '/srv/mariadb/conf/99-databasify.cnf', 'serverScript' => '/srv/mariadb/conf/start-mariadbd', 'runningConfig' => '/srv/mariadb/.running-config', 'newClusterFlag' => '/srv/mariadb/conf/new-cluster']);
});

it('creates galera secrets and keeps its accounts and settings apart', function () {
    $engine = 'mariadb';
    $driver = Database::providerFor($engine);

    expect(array_keys($driver->generateSecrets()))->toBe(['SST_PASSWORD', 'REPLICATION_PASSWORD', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'])
        ->and($driver->accounts()->reservedUsers())->toContain('mariadb.sys', 'sst', 'replicator')
        ->and($driver->accounts()->reservedDatabases())->toContain('databasify', 'mysql')
        ->and($driver->fields())->not->toBeEmpty()
        ->and(Database::providerFor($engine)->supportsPersistence())->toBeFalse()
        ->and(Database::providerFor($engine)->failoverHint())->toContain('Galera')
        ->and($driver->connection()->uriScheme)->toBe('mysql');
});

it('connects without a ca by default', function () {
    $profile = Database::providerFor('mariadb')->connection();

    expect($profile->command)->toEndWith(' --ssl')->not->toContain('{ca}')
        ->and($profile->uriQuery)->toBe('?ssl-mode=REQUIRED')
        ->and($profile->envNote)->toContain("Mysql::ATTR_SSL_CIPHER => 'DEFAULT'")->toContain('Mysql::ATTR_SSL_VERIFY_SERVER_CERT => false');
});

it('explains how to verify with the ca', function () {
    $profile = Database::providerFor('mariadb')->connection();

    expect($profile->commandNote)->toContain('--ssl-ca={ca}')->toContain('add --ssl-verify-server-cert=0')
        ->and($profile->envNote)->toContain('MYSQL_ATTR_SSL_CA=/path/to/{ca}');
});

it('keeps binary logs for three days unless the user picks another retention', function () {
    $database = idleDatabase('mariadb', 1, DatabaseMode::Single, 'ledger');
    $custom = idleDatabase('mariadb', 1, DatabaseMode::Single, 'audit');
    $custom->update(['settings' => $custom->settings->withConfig(['binlog_expire_logs_seconds' => '86400'])]);

    expect(renderedFile($database, '99-databasify.cnf', $database->seed()))->toContain("binlog_expire_logs_seconds = 259200\n")
        ->and(renderedFile($custom->fresh(), '99-databasify.cnf', $custom->seed()))->toContain("binlog_expire_logs_seconds = 86400\n")->not->toContain('259200');
});

it('keeps mariadbd running when a redeploy changes neither its config nor its binaries', function () {
    $database = idleDatabase('mariadb', 1, DatabaseMode::Single);
    $start = view('ssh.engines.mariadb.lifecycle.start', $database->provider()->scriptData()->starting($database->seed(), false))->render();
    $erase = view('ssh.databases.nodes.stop', $database->provider()->scriptData()->stopping(true))->render();

    expect($start)->toContain("restart_unless_current\n")->not->toContain("\nsystemctl restart mariadb.service")
        ->toContain("if runs_current_config; then\n        echo \"mariadb already runs this configuration, no restart needed\"")
        ->toContain("dpkg-query -W -f '\${Version}' galera-4")
        ->and($erase)->toContain('sysctl -q -p "$originals"');
});

it('creates the state transfer user on the seed before the first member joins a new cluster', function () {
    $database = idleDatabase('mariadb', 2);
    $bootstrap = view('ssh.engines.mariadb.lifecycle.start', $database->provider()->scriptData()->starting($database->seed(), true))->render();

    expect($bootstrap)->toContain("CREATE USER IF NOT EXISTS 'sst'@'localhost' IDENTIFIED BY '{$database->settings->secret('SST_PASSWORD')}';")
        ->and(strpos($bootstrap, 'galera_new_cluster'))->toBeLessThan(strpos($bootstrap, "'sst'@'localhost'"));
});

it('starts mariadbd from its recovered galera position and bootstraps only once through a consumed flag', function () {
    $database = idleDatabase('mariadb', 3);
    $server = collect($database->provider()->configFiles($database->seed()))->firstWhere('path', '/srv/mariadb/conf/start-mariadbd');
    $helpers = view('ssh.engines.mariadb.helpers', engineScript('mariadb'))->render();
    $start = view('ssh.engines.mariadb.lifecycle.start', $database->provider()->scriptData()->starting($database->seed(), bootstrapsCluster: true))->render();

    expect($database->provider()->processes()->toArray()['processes'][0]['argv'])->toBe(['/srv/mariadb/conf/start-mariadbd'])
        ->and($server->mode)->toBe('700')
        ->and($server->owner)->toBe('mysql')
        ->and($server->content)->toContain("options=--defaults-extra-file=/srv/mariadb/conf/99-databasify.cnf\nstart_position=\$(galera_recovery \"\$options\")\n")
        ->toContain("record_running_config\nif rm /srv/mariadb/conf/new-cluster 2> /dev/null; then\n    exec mariadbd \"\$options\" \$start_position --wsrep-new-cluster\n")
        ->and(bashErrorsIn($server->content))->toBe('')
        ->and($helpers)->toContain("databasify ctl kill --signal=KILL mariadb\n")->toContain("galera_new_cluster() {\n    stop_server\n    install -m 600 -o mysql -g mysql /dev/null /srv/mariadb/conf/new-cluster\n")
        ->and($start)->toContain("galera_new_cluster\nsynced() { in_galera && wsrep_synced; }\nwait_for 300 synced\n")
        ->not->toContain('systemctl');
});

it('runs the engine tools inside the container against the config under the data root', function () {
    $database = idleDatabase('mariadb', 2);
    $reader = withReadOnlyNode($database);
    $scripts = collect(['cluster.admit' => $database->provider()->scriptData()->admission($reader), 'cluster.recovery-position' => $database->provider()->scriptData()->for($database->seed()), 'lifecycle.wait-ready' => $database->provider()->scriptData()->readiness($database->seed(), false)])
        ->map(fn (array $data, string $name): string => view("ssh.engines.mariadb.{$name}", $data)->render());

    expect($scripts['cluster.admit'])->toContain("databasify ctl run --quiet --collect --unit=mariadb-clone sh -c 'mariadb-backup --defaults-extra-file=/srv/mariadb/conf/99-databasify.cnf --backup")
        ->and($scripts['cluster.recovery-position'])->toContain("    stop_server\n")->toContain('mariadbd --defaults-extra-file=/srv/mariadb/conf/99-databasify.cnf --user=mysql --wsrep-recover')
        ->and($scripts['lifecycle.wait-ready'])->toContain('databasify ctl logs --unit mariadb --lines 20')
        ->and($scripts->implode("\n"))->not->toContain('systemctl')->not->toContain('journalctl')->not->toContain('systemd-run');
});

it('keeps a running member current when only the galera member list changes', function () {
    $database = idleDatabase('mariadb', 3);
    $script = view('ssh.engines.mariadb.cluster.membership', $database->provider()->scriptData()->clustering($database->seed()))->render();

    expect($script)->toContain("runs_current_config && current=1\n")
        ->toContain("[ \"\$current\" = 0 ] || record_running_config\n")
        ->and(bashErrorsIn($script))->toBe('');
});

it('evicts a galera member that is no longer in the database, such as a node removed while unreachable', function (string $strays, bool $evicts) {
    $database = idleDatabase('mariadb', 2);
    $script = view('ssh.engines.mariadb.lines.11_8.cluster.membership', $database->provider()->scriptData()->clustering($database->seed()))->render();
    $members = $database->nodesWithServers()->map(fn ($node): string => "'{$node->privateIp()}'")->implode(',');
    $bin = mariadbStubs([
        'mariadb' => <<<'BASH'
            while [ $# -gt 0 ]; do [ "$1" = -e ] && query=$2; shift; done
            echo "$query" >> "$STUB_DIR/queries"
            case "$query" in
                *wsrep_cluster_members*) [ -z "$STRAYS" ] || echo "$STRAYS" ;;
                *wsrep_on*) echo 1 ;;
            esac
            BASH,
    ]);

    $result = Process::env(['PATH' => "{$bin}:".getenv('PATH'), 'STUB_DIR' => $bin, 'STRAYS' => $strays])->input($script)->run(['bash']);
    $queries = (string) file_get_contents("{$bin}/queries");

    expect($result->successful())->toBeTrue()
        ->and($queries)->toContain("SELECT node_uuid FROM mysql.wsrep_cluster_members WHERE node_name NOT IN ({$members})")
        ->and(str_contains($queries, "SET GLOBAL wsrep_provider_evs_evict = '{$strays}'"))->toBe($evicts);
})->with([
    'a removed member still connected' => ['7c3e-removed', true],
    'only current members' => ['', false],
]);

it('lets a start already in flight finish before deciding whether to restart mariadb', function () {
    $helpers = view('ssh.engines.mariadb.helpers', engineScript('mariadb'))->render();

    expect($helpers)->toContain("starting() { server_active && ! msql \"SELECT 1\" > /dev/null; }\nsettled() { ! starting; }\nrestart_unless_current() {\n    wait_for 21000 settled\n")
        ->toContain('mv /srv/mariadb/.running-config.new /srv/mariadb/.running-config')
        ->not->toContain('processes.json');
});

it('initialises a data directory only for a new or single seed so a clustered seed that lost its disk rebuilds from a peer', function () {
    $clustered = idleDatabase('mariadb', 3);
    $single = idleDatabase('mariadb', 1, DatabaseMode::Single, 'ledger');
    $prepare = fn ($database, bool $fresh): string => view('ssh.engines.mariadb.lifecycle.prepare', $database->provider()->scriptData()->deploy($database->seed(), $fresh))->render();

    expect($prepare($clustered, false))->not->toContain('mariadb-install-db')
        ->and($prepare($clustered, true))->toContain('mariadb-install-db --defaults-extra-file=/srv/mariadb/conf/99-databasify.cnf')
        ->and($prepare($single, false))->toContain('mariadb-install-db')
        ->and($prepare($single, true))->toContain('--datadir="$dir/data" --auth-root-authentication-method=socket --skip-test-db > /dev/null'."\n".'    chown -R mysql:mysql "$dir/data"');
});

it('clones a new read-only node from a healthy standby instead of the primary', function () {
    $database = idleDatabase('mariadb', 2);
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    [$primary, $standby] = $database->nodes()->members()->get()->all();
    $reader = withReadOnlyNode($database);

    expect($database->provider()->cluster()->cloneDonorFor($reader)?->id)->toBe($standby->id)
        ->and(view('ssh.engines.mariadb.cluster.admit', $database->provider()->scriptData()->admission($reader))->render())->toContain("TCP4-LISTEN:4444,bind={$standby->privateIp()},range={$reader->privateIp()}/32")
        ->and(view('ssh.engines.mariadb.lifecycle.start', $database->provider()->scriptData()->starting($reader, false))->render())->toContain("socat -u TCP:{$standby->privateIp()}:4444")->toContain("MASTER_HOST = '{$primary->privateIp()}'");
});

it('retunes without moving the writer when no setting needs a restart', function () {
    $database = withCurrentCertificates(idleDatabase('mariadb', 3));
    $primary = $database->seed();
    $database->startOperation('Retune for server size');
    $facts = '{"os":"Ubuntu 24.04","id":"ubuntu","version_id":"24.04","cpus":2,"memory_mb":4096,"disk_gb":80}'."\n";
    $ssh = fakeSsh()->respond($facts)->respond($facts)->respond($facts)
        ->respond("WRITER ONLINE\n")->respond("MEMBER ONLINE\n")->respond("MEMBER ONLINE\n");
    foreach (range(1, 8) as $ignored) {
        $ssh->respond();
    }
    $ssh->respond("image={$database->image()->reference()}\n")->respond()->respond("runs the current configuration\n")
        ->respond()->respond()->respond()->respond()
        ->respond("WRITER ONLINE\n")->respond("MEMBER ONLINE\n")->respond("MEMBER ONLINE\n");

    runJob(new RetuneDatabaseJob($database));

    expect(collect($ssh->runs)->pluck('script')->filter(fn (string $script): bool => str_contains($script, 'mariadb.lines.11_8.cluster.failover')))->toBeEmpty()
        ->and($ssh->runs[16]['server'])->toBe($primary->server->name)
        ->and($ssh->scriptAt(16))->toContain('databasify step mariadb.lines.11_8.lifecycle.runs-current-config')->toContain("if runs_current_config; then\n    echo \"runs the current configuration\"")
        ->and($ssh->runs[19]['server'])->toBe($primary->server->name)
        ->and($ssh->scriptAt(19))->toContain('databasify step mariadb.lines.11_8.lifecycle.start')->toContain('mariadb already runs this configuration, no restart needed')
        ->and($database->fresh()->operation)->toBeNull();
});

it('moves the writer off before a retune restarts it', function () {
    $database = withCurrentCertificates(idleDatabase('mariadb', 3));
    $database->startOperation('Retune for server size');
    $facts = '{"os":"Ubuntu 24.04","id":"ubuntu","version_id":"24.04","cpus":2,"memory_mb":4096,"disk_gb":80}'."\n";
    $ssh = fakeSsh()->respond($facts)->respond($facts)->respond($facts)
        ->respond("WRITER ONLINE\n")->respond("MEMBER ONLINE\n")->respond("MEMBER ONLINE\n");
    foreach (range(1, 8) as $ignored) {
        $ssh->respond();
    }
    $ssh->respond("image={$database->image()->reference()}\n")->respond()->respond()
        ->respond("WRITER ONLINE\n")->respond("MEMBER ONLINE\n")->respond("MEMBER ONLINE\n");

    runJob(new RetuneDatabaseJob($database));

    expect($ssh->scriptAt(16))->toContain('lifecycle.runs-current-config')
        ->and(collect($ssh->runs)->pluck('script')->filter(fn (string $script): bool => str_contains($script, 'mariadb.lines.11_8.cluster.failover')))->not->toBeEmpty();
});

it('replays only the transactions after the base backup, across members and rotated binlogs', function () {
    $sorted = 'tr , "\n" | sort | paste -s -d , -';

    $result = replayMariadbBinlogs([
        '100-1-mariadb-bin.000001.zst' => ['4 # Gtid list [1-1-2]', binlogEvent(100, '1-1-3'), binlogEvent(200, '1-2-4')],
        '150-2-mariadb-bin.000002.zst' => ['4 # Gtid list [1-1-2]', binlogEvent(100, '1-1-3'), binlogEvent(200, '1-2-4'), binlogEvent(300, '1-1-5'), binlogEvent(400, '1-2-6')],
        '200-1-mariadb-bin.000002.zst' => ['4 #251026  6:20:00 server id 1  end_log_pos 4 \tGtid list [1-2-4,', '4 # 9-1-3]', binlogEvent(100, '1-1-5'), binlogEvent(200, '1-2-6'), binlogEvent(250, '9-1-4'), binlogEvent(300, '1-1-7'), binlogEvent(400, '1-2-8')],
        '300-1-mariadb-bin.000003.zst' => ['4 # Gtid list [1-2-8,9-1-4]', binlogEvent(100, '1-1-9')],
    ], "mariadb-bin.000002 300 9-1-4\n", <<<BASH
        echo "max=\$(merge_positions "1-1-5,1-2-7,1-1-6,9-1-1" "" | {$sorted})"
        echo "rotated=\$(gtid_list_of "\$work/binlogs/200-1-mariadb-bin.000002.zst" | {$sorted})"
        echo "offset=\$(gtid_state_at "\$work/binlogs/200-1-mariadb-bin.000002.zst" 300 | {$sorted})"
        BASH);

    expect($result->errorOutput())->toBe('')
        ->and($result->output())->toContain("max=1-2-7,9-1-1\n")
        ->toContain("rotated=1-2-4,9-1-3\n")
        ->toContain("offset=1-2-6,9-1-4\n")
        ->toContain("replaying from the gtid state of the base backup in 200-1-mariadb-bin.000002.zst\n")
        ->toContain("start=1-2-6,9-1-4\n")
        ->toContain("applied=1-1-7,1-2-8,1-1-9\n");
});

it('refuses to replay when no archived binlog matches the base backup', function () {
    $result = replayMariadbBinlogs([
        '150-2-mariadb-bin.000002.zst' => ['4 # Gtid list [1-1-2]', binlogEvent(100, '1-1-3'), binlogEvent(200, '1-2-4'), binlogEvent(300, '1-1-5')],
    ], "mariadb-bin.000002 300 9-1-4\n");

    expect($result->failed())->toBeTrue()
        ->and($result->output())->toContain("No archived copy of mariadb-bin.000002 matches the base backup (9-1-4); restore to the backup point instead, or check the archive.\n")
        ->not->toContain('start=');
});

it('finds the base backup in a binlog it took right after a rotation, past another member\'s copy of the same name', function () {
    $result = replayMariadbBinlogs([
        '250-2-mariadb-bin.000003.zst' => ['4 # Gtid list [1-1-7,9-2-4]', binlogEvent(100, '1-1-8')],
        '300-1-mariadb-bin.000003.zst' => ['4 # Gtid list [1-2-8,9-1-4]', binlogEvent(100, '1-1-9')],
    ], "mariadb-bin.000003 50 1-2-8\n");

    expect($result->errorOutput())->toBe('')
        ->and($result->output())->toContain("replaying from the gtid state of the base backup in 300-1-mariadb-bin.000003.zst\n")
        ->toContain("start=1-2-8,9-1-4\n")
        ->toContain("applied=1-1-9\n");
});

it('takes the copy without transactions before the offset for a base backup without a gtid', function () {
    $result = replayMariadbBinlogs([
        '90-2-mariadb-bin.000001.zst' => ['4 # Gtid list []', binlogEvent(100, '1-2-1'), binlogEvent(200, '1-2-2')],
        '100-1-mariadb-bin.000001.zst' => ['4 # Gtid list []', binlogEvent(400, '1-1-1')],
    ], "mariadb-bin.000001 300 \n");

    expect($result->errorOutput())->toBe('')
        ->and($result->output())->toContain("replaying from the gtid state of the base backup in 100-1-mariadb-bin.000001.zst\n")
        ->toContain("start=\n");
});

/**
 * @param  array<string, list<string>>  $binlogs
 */
function replayMariadbBinlogs(array $binlogs, string $binlogInfo, string $probes = ''): ProcessResult
{
    $database = withRecovery(idleDatabase('mariadb', 1, DatabaseMode::Single));
    $source = new RecoverySource('orders', $database->pointInTimeRecovery->storage_provider_id, 'mariadb/orders-1', CarbonImmutable::parse('2026-10-26 06:30:15'));
    $replay = view(Database::providerFor('mariadb')->scriptView('archive.replay'), $database->provider()->scriptData()->restoring($database->seed(), $source))->render();
    $bin = mariadbStubs([
        'zstd' => 'cat',
        'rclone' => '[ "$1" = cat ] && exec cat "$2"; ls "${@: -1}"',
        'mariadb' => 'grep -o "GTID [0-9-]*" | cut -c 6- >> "$STUB_DIR/applied"',
        'mariadb-binlog' => <<<'BASH'
            for arg in "$@"; do
                case "$arg" in
                    --stop-position=*) stop=${arg#*=} ;;
                    --start-position=*) start=${arg#*=} ;;
                    --*) ;;
                    *) file=$arg ;;
                esac
            done
            awk -v stop="${stop:-}" -v start="${start:-}" '
                BEGIN { n = split(start, from, ","); for (i = 1; i <= n; i++) { split(from[i], gtid, "-"); seq[gtid[1]] = gtid[3] } }
                { at = $1 + 0; sub(/^[0-9]+ /, "") }
                stop != "" && at >= stop + 0 { exit }
                start != "" && match($0, /GTID [0-9]+-[0-9]+-[0-9]+/) {
                    split(substr($0, RSTART + 5, RLENGTH - 5), gtid, "-")
                    if (!(gtid[1] in seq)) { print "no initial value for domain " gtid[1] > "/dev/stderr"; exit 1 }
                    if (gtid[3] + 0 <= seq[gtid[1]] + 0) { next }
                }
                { print }
            ' "$file"
            BASH,
    ]);
    mkdir("{$bin}/binlogs");
    collect($binlogs)->each(fn (array $lines, string $name) => file_put_contents("{$bin}/binlogs/{$name}", implode("\n", $lines)."\n"));
    file_put_contents("{$bin}/binlog-info", $binlogInfo);
    $sorted = 'tr , "\n" | sort | paste -s -d , -';

    return Process::env(['PATH' => "{$bin}:".getenv('PATH'), 'STUB_DIR' => $bin])->input(<<<BASH
        set -euo pipefail
        work={$bin}; ARCHIVE={$bin}; started=50
        sql() { merge_positions "" "\$(tr '\\n' , < "\$work/applied")"; }
        touch "\$work/applied"
        {$replay}
        {$probes}
        before_replay
        echo "start=\$(echo "\$position" | {$sorted})"
        for name in \$(ls "\$work/binlogs" | sort -t - -k 1,1n); do replay_binlog "\$work/binlogs/\$name"; done
        echo "applied=\$(paste -s -d , - < "\$work/applied")"
        BASH)->run(['bash']);
}

function binlogEvent(int $at, string $gtid): string
{
    return "{$at} #251026  6:20:00 server id 1  end_log_pos {$at} CRC32 0x00000000 \tGTID {$gtid} trans";
}

/**
 * @param  array<string, string>  $commands
 */
function mariadbStubs(array $commands): string
{
    $bin = mariadbStubsRoot().uniqid();
    mkdir($bin);

    foreach (['timeout' => 'shift; exec "$@"', ...$commands] as $name => $body) {
        file_put_contents("{$bin}/{$name}", "#!/bin/bash\n{$body}\n");
        chmod("{$bin}/{$name}", 0755);
    }

    return $bin;
}

function mariadbStubsRoot(): string
{
    return sys_get_temp_dir().'/mariadb-stubs-'.getmypid().'-';
}

it('forgets the saved galera view only on a joining member, so a member removed by force can rejoin', function () {
    $database = idleDatabase('mariadb', 3);
    $joining = $database->nodesWithServers()->last();
    $prepare = fn ($node, bool $fresh): string => view('ssh.engines.mariadb.lifecycle.prepare', $database->provider()->scriptData()->deploy($node, $fresh))->render();

    expect($prepare($joining, true))->toContain('rm -f "$dir/data/gvwstate.dat"')
        ->and($prepare($joining, false))->not->toContain('gvwstate.dat')
        ->and($prepare($database->seed(), true))->not->toContain('gvwstate.dat');
});

it('evicts nobody when the database has no galera members left', function () {
    $database = idleDatabase('mariadb', 2);
    $script = view('ssh.engines.mariadb.cluster.membership', [...$database->provider()->scriptData()->clustering($database->seed()), 'hosts' => ''])->render();

    expect($script)->not->toContain('wsrep_provider_evs_evict')->not->toContain('wsrep_cluster_members')
        ->and(bashErrorsIn($script))->toBe('');
});
