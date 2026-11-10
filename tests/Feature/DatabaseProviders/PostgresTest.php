<?php

declare(strict_types=1);

use App\DTOs\Migrations\MigrationSource;
use App\DTOs\Recovery\RecoverySource;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\NodeRole;
use App\Enums\Databases\PoolMode;
use App\Models\Database;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('runs postgres with patroni settings for members and read-only nodes', function () {
    $database = idleDatabase('postgres', 3, name: 'orders');
    $engine = Database::providerFor('postgres');
    $reader = withReadOnlyNode($database);
    [$seed, $standby] = $database->nodes()->members()->get()->all();
    $members = $database->nodes()->members()->get()->map->privateIp()->implode(',');

    $patroni = Yaml::parse(renderedFile($database, 'patroni.yml', $standby));
    $readerPatroni = Yaml::parse(renderedFile($database, 'patroni.yml', $reader));
    $etcdHosts = $database->nodes()->members()->get()->map(fn ($member): string => "{$member->privateIp()}:2379")->implode(',');

    expect($database->settings->secret('PGBOUNCER_AUTH_PASSWORD'))->toHaveLength(32)
        ->and($database->provider()->processes($standby)->names())->toBe(['databasify-postgres-locks', 'databasify-etcd', 'databasify-patroni', 'databasify-pgbouncer'])
        ->and($database->provider()->processes($reader)->names())->toBe(['databasify-postgres-locks', 'databasify-patroni', 'databasify-pgbouncer'])
        ->and($database->provider()->processes()->names())->toBe(['databasify-postgres-locks', 'databasify-etcd', 'databasify-patroni', 'databasify-postgres', 'databasify-pgbouncer'])
        ->and($database->provider()->processes($standby)->toArray()['processes'][2])->toMatchArray(['after' => ['databasify-postgres-locks', 'databasify-etcd'], 'stopSignal' => 'SIGTERM', 'stopTimeout' => '2m'])
        ->and($patroni['name'])->toBe($standby->privateIp())
        ->and($patroni['etcd3']['hosts'])->toBe($etcdHosts)
        ->and($patroni['postgresql']['listen'])->toBe('*:25060')
        ->and($patroni['postgresql']['connect_address'])->toBe("{$standby->privateIp()}:25060")
        ->and($patroni['postgresql']['data_dir'])->toBe('/srv/postgres/data')
        ->and($patroni['postgresql']['authentication']['replication']['password'])->toBe($database->settings->secret('REPLICATION_PASSWORD'))
        ->and($patroni['bootstrap']['dcs']['postgresql']['parameters'])->toHaveKeys(['wal_level', 'max_connections', 'max_worker_processes'])
        ->and($patroni['postgresql']['parameters'])->toHaveKeys(['ssl', 'shared_buffers', 'work_mem', 'max_slot_wal_keep_size'])->not->toHaveKey('max_connections')
        ->and($patroni['bootstrap']['dcs']['failsafe_mode'])->toBeTrue()
        ->and($patroni['postgresql'])->toMatchArray(['remove_data_directory_on_rewind_failure' => true, 'remove_data_directory_on_diverged_timelines' => true])
        ->and($patroni['postgresql']['pg_hba'])->toContain('host replication replicator 127.0.0.1/32 scram-sha-256', 'host replication replicator ::1/128 scram-sha-256')
        ->and($patroni['tags'])->toBe(['nofailover' => false, 'noloadbalance' => false, 'nosync' => false, 'clonefrom' => false])
        ->and($readerPatroni['tags'])->toBe(['nofailover' => true, 'noloadbalance' => true, 'nosync' => true, 'clonefrom' => true])
        ->and(renderedFile($database, 'etcd.env', $reader))->toBe('')
        ->and($database->provider()->environment($database->endpoints()))->toMatchArray(['DB_CONNECTION' => 'pgsql', 'DB_PORT' => '25060', 'DB_SSLMODE' => 'require'])
        ->and($database->provider()->ports()->client)->toBe(25060)
        ->and($database->provider()->ports()->pool)->toBe(25061)
        ->and($database->provider()->ports()->peers)->toBe([8008, 2379, 2380])
        ->and($engine->canFailover($database->nodes))->toBeTrue();
});

it('reads patroni roles and only counts running postgres', function () {
    $engine = Database::providerFor('postgres');

    expect($engine->role("role:primary state:running\nversion:18.6"))->toBe(NodeRole::Primary)
        ->and($engine->role('role:replica state:streaming'))->toBe(NodeRole::Replica)
        ->and($engine->role('role:replica state:starting'))->toBe(NodeRole::Unknown)
        ->and($engine->role('role:unknown state:unknown'))->toBe(NodeRole::Unknown);
});

it('writes postgres account statements that grant schema access per database', function () {
    expect(accountSql('postgres', 'create-user', ['name' => 'reports', 'password' => 'secret', 'databases' => ['app']]))->toContain("WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'reports') \\gexec")->toContain('ALTER ROLE "reports" LOGIN PASSWORD \'secret\';')->toContain('\connect "app"')->toContain('GRANT ALL ON SCHEMA public TO "reports";')
        ->and(accountSql('postgres', 'create-database', ['name' => 'shop', 'users' => []]))->toContain("WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'shop') \\gexec")
        ->and(accountSql('postgres', 'drop-user', ['name' => 'reports', 'databases' => ['app', 'shop']]))->toContain("\\connect \"shop\"\nREASSIGN OWNED BY \"reports\" TO postgres;")->toEndWith("DROP ROLE IF EXISTS \"reports\";\n")
        ->and(accountSql('postgres', 'drop-database', ['name' => 'shop']))->toBe("DROP DATABASE IF EXISTS \"shop\" WITH (FORCE);\n");

    expect(accountSql('postgres', 'create-database', ['name' => 'app', 'users' => []]).";\n".accountSql('postgres', 'create-user', ['name' => 'app', 'password' => 'secret', 'databases' => ['app']]))->not->toContain('\gexec;');
});

it('keeps its version line and reserves the internal roles', function () {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single, versionLine: '17');

    $this->get(projectUrl("/databases/{$database->id}"))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('database.data.version_line', '17')
        ->where('database.data.pool_port', 25061)
        ->where('database.data.connection.uri_scheme', 'postgresql'));
    $this->post(projectUrl("/databases/{$database->id}/users"), ['name' => 'replicator'])->assertSessionHasErrors('name');
    $this->post(projectUrl("/databases/{$database->id}/schemas"), ['name' => 'template1'])->assertSessionHasErrors('name');
});

it('needs logical replication on the source to keep a postgres migration in sync', function () {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    fakeSsh()
        ->respond("reachable=yes\nversion=16.4\nwal_level=replica\ncan_replicate=f\nsize_mb=120\ndatabases=shop\n")
        ->respond("reachable=yes\nversion=17.2\nwal_level=logical\ncan_replicate=t\nsize_mb=120\ndatabases=shop\n");
    $source = new MigrationSource('db.db.example.com', 5432, 'migrator', 'secret', true, []);
    $migration = $database->provider()->migration();

    $blocked = $migration->check($source);
    $ready = $migration->check($source);

    expect($blocked->reachable)->toBeTrue()
        ->and($blocked->supportsContinuous())->toBeFalse()
        ->and($blocked->blockers)->toHaveCount(2)
        ->and($ready->supportsContinuous())->toBeTrue()
        ->and($ready->fact('databases'))->toBe('shop');
});

it('reports target databases that already hold rows before a postgres migration copies into them', function () {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    $ssh = fakeSsh()->respond("reachable=yes\nversion=18.6\nwal_level=logical\ncan_replicate=t\ndatabases=shop blog\noccupied=shop\n");

    $report = $database->provider()->migration()->check(new MigrationSource('db.example.com', 5432, 'migrator', 'secret', true, ['shop', 'blog']));

    expect($report->occupied)->toBe(['shop'])
        ->and($ssh->scriptAt(0))->toContain("wanted='shop blog'")
        ->toContain('printf \'%s\n\' "$existing" | grep -Fxq "$db" || continue')
        ->toContain('[ "$(in_pg psql -X -U postgres -d "$db" -Atc "SELECT 1 FROM ($probe) found LIMIT 1" || true)" != "1" ] || occupied="$occupied $db"');
});

it('drops the migration publication on the source when a postgres migration stops', function () {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    $script = view('ssh.engines.postgres.migrations.stop', [...$database->provider()->scriptData()->for($database->seed()), 'stateDir' => '/srv/postgres/migration'])->render();

    expect($script)->toContain('publisher=$(source_conninfo "$db" "$name")')
        ->toContain('in_pg psql "$publisher connect_timeout=10" -Xqc "DROP PUBLICATION IF EXISTS \\"$publication\\""');
});

it('lets wal_level be raised to logical cluster-wide with a restart', function () {
    $database = idleDatabase('postgres', 2);
    $database->update(['settings' => $database->settings->withConfig(['wal_level' => 'logical'])]);
    $field = collect($database->provider()->fields())->firstWhere('key', 'wal_level');

    expect($field->needsRestart())->toBeTrue()
        ->and(Yaml::parse(renderedFile($database, 'patroni.yml'))['bootstrap']['dcs']['postgresql']['parameters']['wal_level'])->toBe('logical');
});

it('restarts for autovacuum workers only on lines before 18, which reload them', function (string $line, bool $restarts) {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single, versionLine: $line);
    $field = collect($database->provider()->fields())->firstWhere('key', 'autovacuum_max_workers');

    expect($field->needsRestart())->toBe($restarts);
})->with([['17', true], ['18', false]]);

it('runs the server and tunes autovacuum workers of the default line without a database', function () {
    $engine = Database::providerFor('postgres');
    $server = collect($engine->processes()->toArray()['processes'])->firstWhere('name', 'databasify-postgres');

    expect($server['argv'][0] ?? null)->toBe('/usr/lib/postgresql/18/bin/postgres')
        ->and(collect($engine->fields())->firstWhere('key', 'autovacuum_max_workers')->needsRestart())->toBeFalse();
});

it('passes connection pools to pgbouncer by name', function () {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    $database->connectionPools()->create(['name' => 'web', 'schema' => 'app', 'mode' => PoolMode::Transaction, 'size' => 20]);
    $database->connectionPools()->create(['name' => 'jobs', 'schema' => 'app', 'mode' => PoolMode::Session, 'size' => 5]);

    expect(renderedFile($database, 'pgbouncer.ini'))
        ->toContain("postgres = host=127.0.0.1 port=25060 dbname=postgres pool_size=2\njobs = host=127.0.0.1 port=25060 dbname=app pool_size=5 pool_mode=session\nweb = host=127.0.0.1 port=25060 dbname=app pool_size=20 pool_mode=transaction\n")
        ->toContain("listen_port = 25061\n")
        ->and(renderedFile($database, 'pgbouncer-users.txt'))->toBe('"pgbouncer" "'.$database->settings->secret('PGBOUNCER_AUTH_PASSWORD')."\"\n");
});

it('gives apps a digitalocean style env that requires tls, with or without a domain', function () {
    $database = withZone(idleDatabase('postgres', 1, DatabaseMode::Single, 'orders'));

    expect($database->provider()->environment($database->endpoints()))
        ->toMatchArray(['DB_HOST' => 'orders.db.example.com', 'DB_SSLMODE' => 'require'])
        ->not->toHaveKey('DB_SSL_CA_B64');
});

it('connects without a ca by default', function () {
    $profile = Database::providerFor('postgres')->connection();

    expect($profile->command)->toStartWith('PGSSLMODE=require PGPASSWORD={password} psql ')->not->toContain('{ca}')
        ->and($profile->uriQuery)->toBe('?sslmode=require');
});

it('explains how to verify with the ca', function () {
    $profile = Database::providerFor('postgres')->connection();

    expect($profile->commandNote)->toContain('PGSSLMODE=verify-full PGSSLROOTCERT={ca}')
        ->and($profile->envNote)->toContain('sslrootcert=/path/to/{ca}');
});

it('reads standby leaders as replicas', function () {
    $engine = Database::providerFor('postgres');

    expect($engine->role('role:standby state:streaming'))->toBe(NodeRole::Replica)
        ->and($engine->role('role:standby_leader state:running'))->toBe(NodeRole::Replica);
});

it('describes the postgres layout, limits and scripts', function () {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    $engine = 'postgres';
    $driver = Database::providerFor($engine);

    expect(Database::providerFor($engine)->systemUser())->toBe('postgres')
        ->and(Database::providerFor($engine)->directory())->toBe('/srv/postgres')
        ->and($database->provider()->processes($database->seed())->names())->toBe(['databasify-postgres-locks', 'databasify-postgres', 'databasify-pgbouncer'])
        ->and(renderedFile($database, 'postgresql.conf'))->toContain("listen_addresses = '*'\n")->toContain("hba_file = '/srv/postgres/conf/pg_hba.conf'\n")->toContain("wal_level = 'replica'\n")
        ->and(renderedFile($database, 'pg_hba.conf'))->toContain("hostssl all all 0.0.0.0/0 scram-sha-256\n")
        ->and(renderedFile($database, 'postgresql.conf'))->toContain("unix_socket_directories = '/srv/postgres/run'\n")
        ->and($database->provider()->processes($database->seed())->toArray()['processes'][0])->toMatchArray([
            'type' => 'oneshot',
            'argv' => ['sh', '-c', 'pgrep -x postgres > /dev/null || rm -f /srv/postgres/run/.s.PGSQL.* /srv/postgres/data/postmaster.pid'],
            'enabled' => true,
        ])
        ->and($database->provider()->processes($database->seed())->toArray()['processes'][1])->toMatchArray([
            'argv' => ['/usr/lib/postgresql/18/bin/postgres', '-D', '/srv/postgres/data', '-c', 'config_file=/srv/postgres/conf/postgresql.conf'],
            'user' => 'postgres',
            'after' => ['databasify-postgres-locks'],
            'stopSignal' => 'SIGINT',
            'enabled' => false,
        ])
        ->and($database->provider()->processes($database->seed())->toArray()['processes'][2])->toMatchArray(['after' => ['databasify-postgres', 'databasify-patroni']])
        ->and($driver->clientAddress($database->seed()))->toBe($database->seed()->privateIp().':25060')
        ->and(Database::providerFor($engine)->scriptView('config.pools'))->toBe('ssh.engines.postgres.lines.18.config.pools')
        ->and(Database::providerFor($engine)->scriptView('cluster.forget'))->toBe('ssh.engines.postgres.lines.18.cluster.forget')
        ->and(Database::providerFor($engine)->scriptView('lifecycle.cleanup'))->toBe('ssh.engines.postgres.lines.18.lifecycle.cleanup')
        ->and(Database::providerFor($engine)->scriptView('cluster.force-quorum'))->toBe('ssh.engines.postgres.lines.18.cluster.force-quorum')
        ->and(array_keys($driver->generateSecrets()))->toBe(['POSTGRES_PASSWORD', 'REPLICATION_PASSWORD', 'PATRONI_API_PASSWORD', 'PGBOUNCER_AUTH_PASSWORD', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'])
        ->and($driver->accounts()->reservedUsers())->toContain('postgres', 'replicator', 'pgbouncer', 'patroni')
        ->and($database->provider()->membershipVariables())->toBe(['hosts' => $database->seed()->privateIp()]);
});

it('forgets a removed etcd member only while etcd keeps its majority', function () {
    $script = view('ssh.engines.postgres.cluster.forget', engineScript('postgres', ['instance' => '10.0.0.7:25060']))->render();

    expect($script)->toContain("if ! etcd_ctl endpoint health > /dev/null 2>&1; then\n    echo \"etcd lost its majority without \$ip, clustering is switched off next\"\n    exit 0\nfi")
        ->toContain('ip=10.0.0.7')
        ->toContain('peer="http://$ip:2380"');
});

it('drops the slots and patroni state of former members once postgres runs on its own', function () {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);

    expect(view('ssh.engines.postgres.cluster.uncluster', $database->provider()->scriptData()->clustering($database->seed()))->render())
        ->toContain("pg \"SELECT count(pg_drop_replication_slot(slot_name)) FROM pg_replication_slots WHERE slot_type = 'physical' AND NOT active\"")
        ->toContain('rm -rf "$dir/etcd" "$dir/conf/patroni.yml" "$dir/conf/etcd.env" "$dir/conf/etcd-initial.env" "$dir/conf/etcd-force.env" "$dir/conf/pgpass"')
        ->toContain('rm -f "$dir/data/standby.signal" "$dir/data/patroni.dynamic.json"')
        ->toContain("databasify ctl enable --now databasify-postgres > /dev/null\ndatabasify ctl restart databasify-pgbouncer");
});

it('replaces the patroni settings and restarts the primary first when a standby limit is lowered', function () {
    $database = idleDatabase('postgres', 2);
    $script = view('ssh.engines.postgres.config.sync-config', [...$database->provider()->scriptData()->for($database->seed()), 'files' => []])->render();

    expect($script)->toContain('patroni_ctl edit-config --force --replace "$dir/conf/dcs.yml"')
        ->toContain("for name in ('max_connections', 'max_locks_per_transaction', 'max_prepared_transactions', 'max_wal_senders', 'max_worker_processes'):")
        ->toContain('[ -n "$wanted" ] || wanted=$(pg "SELECT boot_val FROM pg_settings WHERE name = \'$name\'")')
        ->toContain("if lowers_standby_limits && wait_for 60 restart_pending; then\n    patroni_ctl restart main --role primary --force > /dev/null\n    wait_for 300 leading");
});

it('registers a member again in etcd when its etcd data is gone or a forced recovery dropped it', function () {
    $database = idleDatabase('postgres', 3);
    $seed = $database->seed();
    $redeploy = view('ssh.engines.postgres.lifecycle.prepare', $database->provider()->scriptData()->deploy($seed))->render();
    $join = view('ssh.engines.postgres.lifecycle.prepare', $database->provider()->scriptData()->deploy($seed, fresh: true))->render();
    $reader = view('ssh.engines.postgres.lifecycle.prepare', $database->provider()->scriptData()->deploy(withReadOnlyNode($database)))->render();

    expect($redeploy)->toContain("install -d -m 700 -o postgres -g postgres \"\$dir/data\" \"\$dir/run\"\ninstall -d -m 750 -o postgres -g postgres \"\$dir/archive\" \"\$dir/archive/log\"")
        ->toContain("peer_url=http://{$seed->privateIp()}:2380")
        ->toContain("endpoint=\$(answering_etcd {$seed->privateIp()} || true)")
        ->toContain('answering_etcd() { for endpoint in $(other_etcd_endpoints "$1"); do')
        ->toContain("elif [ -n \"\$listed\" ] && [ ! -d \"\$dir/etcd/member\" ]; then\n    other_etcd member remove \"\$listed\" > /dev/null\n    register_again")
        ->toContain("elif [ -z \"\$listed\" ]; then\n    databasify ctl stop databasify-etcd\n    rm -rf \"\$dir/etcd/member\"\n    register_again")
        ->toContain("other_etcd member add {$seed->privateIp()} --peer-urls=\"\$peer_url\" --learner | grep '^ETCD_' | put_file \"\$dir/conf/etcd-initial.env\" 640 postgres")
        ->and($join)->toContain('elif [ -z "$listed" ] && [ -d "$dir/etcd/member" ]; then')
        ->and($reader)->not->toContain('member add')->not->toContain('$dir/etcd');
});

it('starts etcd from its environment files, never bootstraps a member the cluster still lists, and stops waiting when patroni keeps failing', function () {
    $database = idleDatabase('postgres', 2);
    $etcd = $database->provider()->processes($database->seed())->toArray()['processes'][1];
    $cluster = view('ssh.engines.postgres.cluster.cluster', $database->provider()->scriptData()->clustering($database->seed()))->render();

    expect($etcd['name'])->toBe('databasify-etcd')
        ->and(array_slice($etcd['argv'], 0, 2))->toBe(['sh', '-c'])
        ->and($etcd['argv'][2])->toStartWith('set -a; . /srv/postgres/conf/etcd.env; for extra in /srv/postgres/conf/etcd-initial.env /srv/postgres/conf/etcd-force.env; do [ ! -f "$extra" ] || . "$extra"; done; set +a')
        ->toContain('if [ "$ETCD_INITIAL_CLUSTER_STATE" = new ] && [ ! -d "$ETCD_DATA_DIR/member" ]; then')
        ->toContain('awk -F \', \' -v peer="$ETCD_INITIAL_ADVERTISE_PEER_URLS" \'$4 == peer { listed = 1 } END { exit !listed }\'; then')
        ->toContain("exit 1\n        fi\n    done\nfi\nexec /usr/local/bin/etcd")
        ->and($cluster)->toContain('databasify ctl reset-failed databasify-patroni')
        ->toContain('if crash_looping databasify-patroni 3; then')
        ->and(view('ssh.engines.postgres.helpers', engineScript('postgres'))->render())->toContain('restarts=$(databasify ctl show -p NRestarts --value "$1" 2>/dev/null || true)')->toContain('databasify ctl logs --unit "$1" --lines 20');
});

it('recovers a fully stopped cluster by starting patroni on the most advanced member first', function () {
    $database = idleDatabase('postgres', 3);
    $engine = 'postgres';
    $base = $database->provider()->scriptData()->for($database->seed());
    $forceQuorum = view(Database::providerFor($engine)->scriptView('cluster.force-quorum'), [...$base, 'othersAreDown' => false])->render();

    expect(Database::providerFor($engine)->scriptView('lifecycle.wake'))->toBe('ssh.engines.postgres.lines.18.lifecycle.wake')
        ->and(Database::providerFor($engine)->scriptView('cluster.rejoin'))->toBe('ssh.engines.postgres.lines.18.cluster.rejoin')
        ->and(view(Database::providerFor($engine)->scriptView('lifecycle.wake'), $base)->render())->toContain('databasify ctl start databasify-etcd')->not->toContain('start databasify-patroni')
        ->and(view(Database::providerFor($engine)->scriptView('cluster.recovery-position'), $base)->render())->toContain('Latest checkpoint location\\|Minimum recovery ending location')
        ->and($forceQuorum)->toContain("if ! wait_for 120 etcd_up > /dev/null; then\n    echo \"etcd has no majority, start the other members' servers and recover again\"\n    exit 1")
        ->toContain('databasify ctl start databasify-patroni databasify-pgbouncer')->toContain('wait_for 300 has_leader')
        ->not->toContain('ETCD_FORCE_NEW_CLUSTER')->not->toContain('patroni_ctl failover')
        ->and(Process::input($forceQuorum)->run(['bash', '-n'])->successful())->toBeTrue();
});

it('forces etcd into a single-member cluster on the survivor once the user confirms the others are down', function () {
    $database = idleDatabase('postgres', 3);
    $survivor = $database->nodes()->get()[1];
    $script = view(Database::providerFor('postgres')->scriptView('cluster.force-quorum'), [...$database->provider()->scriptData()->for($survivor), 'othersAreDown' => true])->render();

    expect($script)->toContain('if ! wait_for 30 etcd_up > /dev/null; then')
        ->toContain("echo 'ETCD_FORCE_NEW_CLUSTER=true' | put_file \"\$forced\" 640 postgres\n    databasify ctl restart databasify-etcd")
        ->toContain("rm -f \"\$forced\"\n    databasify ctl restart databasify-etcd")
        ->toContain("sed -i \"s|^  hosts: .*|  hosts: '{$survivor->privateIp()}:2379'|\" \"\$dir/conf/patroni.yml\"")
        ->toContain("patroni_ctl failover --candidate {$survivor->privateIp()} --force")
        ->toContain('[ -z "${forced_alone:-}" ] || wait_for 120 serving')
        ->not->toContain('etcd has no majority')
        ->and(Process::input($script)->run(['bash', '-n'])->successful())->toBeTrue();
});

it('counts quorum from the voting etcd members and falls back to the patroni hosts', function () {
    expect(view(Database::providerFor('postgres')->scriptView('cluster.quorum'), engineScript('postgres'))->render())
        ->toContain("etcd_ctl member list --consistency=s -w json 2> /dev/null || true; } | jq -r '.members[]? | select(.isLearner | not) | .peerURLs[0] // empty'")
        ->toContain('/conf/patroni.yml')->toContain('echo "online=$online"');
});

it('promotes a member that came back as an etcd learner before waiting for postgres, which patroni cannot start through a learner', function () {
    $database = idleDatabase('postgres', 3);
    $seed = $database->seed();
    $ready = view(Database::providerFor('postgres')->scriptView('lifecycle.wait-ready'), $database->provider()->scriptData()->readiness($seed, expectPrimary: false))->render();

    expect($ready)->toContain("awk -F ', ' -v peer=\"http://{$seed->privateIp()}:2380\" '\$4 == peer && \$6 == \"true\" { print \$1 }'")
        ->toContain('promoted() { timeout 30 etcdctl --endpoints="$endpoint" member promote "$learner" < /dev/null > /dev/null 2>&1; }')
        ->toMatch('/wait_for 300 promoted\n.*\n.*\nwait_for 21000 ready\n/')
        ->and(view(Database::providerFor('postgres')->scriptView('lifecycle.wait-ready'), $database->provider()->scriptData()->readiness(withReadOnlyNode($database), expectPrimary: false))->render())->not->toContain('member promote');
});

it('switches and checkpoints the wal after a primary starts so archiving resumes at once', function () {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);

    expect(view(Database::providerFor('postgres')->scriptView('lifecycle.wait-ready'), $database->provider()->scriptData()->readiness($database->seed(), expectPrimary: true))->render())
        ->toContain("if [ \"\$(own_role)\" = \"primary\" ] && [ \"\$(pg 'SHOW archive_mode' || true)\" = \"on\" ]; then\n    pg \"SELECT pg_switch_wal()\" > /dev/null\n    pg \"CHECKPOINT\" > /dev/null");
});

it('replays a restore with the standby limits the source ran with', function () {
    $database = withRecovery(idleDatabase('postgres', 1, DatabaseMode::Single));
    $source = new RecoverySource('orders', $database->pointInTimeRecovery->storage_provider_id, 'postgres/orders-1', CarbonImmutable::parse('2026-10-26 06:30:15'));

    expect(view(Database::providerFor('postgres')->scriptView('archive.restore'), $database->provider()->scriptData()->restoring($database->seed(), $source))->render())
        ->toContain('/usr/lib/postgresql/18/bin/postgres -D "$dir/data" -c config_file="$dir/conf/postgresql.conf" -C "$name"')
        ->toContain("-e 's/^max_locks_per_xact setting: *\\([0-9]*\\)$/max_locks_per_transaction \\1/p'")
        ->toContain('echo "$name = \'$value\'" >> "$dir/data/postgresql.auto.conf"')
        ->toContain('for setting in restore_command recovery_target_time recovery_target_action recovery_target_timeline $raised; do');
});

it('lets new replicas clone from read-only nodes instead of the primary', function () {
    $database = idleDatabase('postgres', 3);
    $reader = withReadOnlyNode($database);

    expect(Yaml::parse(renderedFile($database, 'patroni.yml', $reader))['tags']['clonefrom'])->toBeTrue()
        ->and(Yaml::parse(renderedFile($database, 'patroni.yml', $database->seed()))['tags']['clonefrom'])->toBeFalse();
});

it('changes no other node when a read-only node joins', function () {
    $database = idleDatabase('postgres', 3);
    $reader = withReadOnlyNode($database);

    expect($database->provider()->cluster()->membershipChangesFor($reader))->toBeFalse()
        ->and($database->provider()->cluster()->membershipChangesFor($database->seed()))->toBeTrue()
        ->and(idleDatabase('mongodb', 3, name: 'orders')->provider()->cluster()->membershipChangesFor($reader))->toBeTrue();
});
