<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\NodeRole;
use App\Enums\Databases\Persistence;
use App\Models\Database;
use Illuminate\Support\Facades\Process;

dataset('sentinel engines', [
    'valkey' => ['valkey', 'VALKEY'],
    'redis' => ['redis', 'REDIS'],
]);

it('writes non-promotable valkey replicas that follow the primary', function () {
    $database = idleDatabase('valkey', 2);
    $reader = withReadOnlyNode($database);
    [$seed] = $database->nodes()->get()->all();

    expect(renderedFile($database, 'valkey.conf', $reader))->toContain("replica-priority 0\n")->toContain("replicaof {$seed->privateIp()} 6379\n")
        ->and(renderedFile($database, 'valkey.conf', $seed))->not->toContain('replica-priority')->not->toContain('replicaof')
        ->and(renderedFile($database, 'sentinel.conf', $reader))->toContain("sentinel monitor main {$seed->privateIp()} 6379 2\n");
});

it('boots a clustered node in the role its peer sentinels report and refuses to restart it as a primary without data', function (string $engine) {
    $database = idleDatabase($engine, 3);
    [$seed, $standby, $member] = $database->nodes()->get()->all();
    $render = fn ($node): string => view("ssh.engines.{$engine}.lifecycle.prepare", $database->provider()->scriptData()->deploy($node))->render();

    expect($render($standby))->toContain('SENTINEL get-master-addr-by-name main')
        ->toContain("for peer in {$seed->privateIp()} {$standby->privateIp()} {$member->privateIp()}; do")
        ->toContain("[ \"\$peer\" = '{$standby->privateIp()}' ] && continue")
        ->toContain("sed -i '/^replicaof /d' \"\$conf\"")
        ->toContain('if ! grep -q \'^replicaof \' "$conf" && restart_loses_data && peer_has_keys; then')
        ->toContain('refusing to start as an empty primary while a peer still holds the data: move the primary to that peer first" > "$start_refused"')
        ->and(view("ssh.engines.{$engine}.lifecycle.prepare", ($single = idleDatabase($engine, 1, DatabaseMode::Single, 'solo'))->provider()->scriptData()->deploy($single->seed()))->render())->not->toContain('SENTINEL');
})->with('sentinel engines');

it('makes a held back replica promotable once its first sync finishes, but never a read-only node', function (string $engine) {
    $database = idleDatabase($engine, 2);
    [, $standby] = $database->nodes()->get()->all();
    $reader = withReadOnlyNode($database);
    $render = fn ($node): string => view("ssh.engines.{$engine}.lifecycle.wait-ready", ($database->fresh() ?? $database)->provider()->scriptData()->readiness($node, expectPrimary: false))->render();

    expect($render($standby))->toContain('CONFIG SET replica-priority 100')->toContain("if ! grep -q '^replicaof ' \"\$conf\"; then")
        ->and($render($reader))->not->toContain('CONFIG SET replica-priority 100');
})->with('sentinel engines');

it('runs a single node without sentinel or replication settings', function (string $engine, string $prefix) {
    $database = idleDatabase($engine, 1, DatabaseMode::Single);
    $driver = Database::providerFor($engine);
    $database->update(['settings' => $database->settings->withPersistence(Persistence::Off)->withConfig(['maxmemory-policy' => 'allkeys-lru'])]);
    $database = $database->fresh() ?? $database;
    $config = renderedFile($database, "{$engine}.conf");

    expect(Database::providerFor($engine)->directory())->toBe("/srv/{$engine}")
        ->and(Database::providerFor($engine)->systemUser())->toBe($engine)
        ->and($database->provider()->processes()->names())->toBe(["databasify-{$engine}"])
        ->and(array_keys($driver->generateSecrets()))->toBe(["{$prefix}_PASSWORD"])
        ->and($driver->scriptVariables())->toMatchArray(['flavor' => $engine, 'serverUnit' => "databasify-{$engine}"])
        ->and($config)->toContain("bind * -::*\n")
        ->toContain('requirepass "'.$database->settings->secret("{$prefix}_PASSWORD").'"')
        ->toContain("save \"\"\nappendonly no\n")
        ->toContain("maxmemory-policy allkeys-lru\n")
        ->not->toContain('replica-announce-ip')
        ->and(renderedFile($database, 'sentinel.conf'))->toBe('');
})->with('sentinel engines');

it('serves clients on the tls port and talks to peers on the data and sentinel ports', function (string $engine) {
    expect(Database::providerFor($engine)->ports()->client)->toBe(25061)
        ->and(Database::providerFor($engine)->ports()->peers)->toBe([6379, 26379])
        ->and(Database::providerFor($engine)->ports()->pool)->toBeNull()
        ->and(Database::providerFor($engine)->tls()->files)->toBe(['ca.crt', 'server.pem'])
        ->and(Database::providerFor($engine)->supportsPersistence())->toBeTrue()
        ->and(Database::providerFor($engine)->accounts())->toBeNull();
})->with('sentinel engines');

it('fails over on its own only with three voters and a standby', function (string $engine) {
    $single = idleDatabase($engine, 1, DatabaseMode::Single, 'single');
    withReadOnlyNode($single);
    withReadOnlyNode($single);
    $pair = idleDatabase($engine, 2, name: 'pair');
    $trio = idleDatabase($engine, 3, name: 'trio');
    $driver = Database::providerFor($engine);

    expect($driver->canFailover($single->nodes()->get()))->toBeFalse()
        ->and($driver->canFailover($pair->nodes()->get()))->toBeFalse()
        ->and($driver->canFailover($trio->nodes()->get()))->toBeTrue()
        ->and($trio->provider()->membershipVariables())->toBe(['quorum' => 2, 'requiredReplicas' => 1])
        ->and($pair->provider()->membershipVariables())->toBe(['quorum' => 2, 'requiredReplicas' => 0]);
})->with('sentinel engines');

it('gives apps a digitalocean style tls redis connection', function (string $engine, string $prefix) {
    $database = idleDatabase($engine, 1, DatabaseMode::Single);

    expect($database->provider()->environment($database->endpoints()))->toBe([
        'REDIS_CLIENT' => 'phpredis',
        'REDIS_SCHEME' => 'tls',
        'REDIS_HOST' => $database->endpoints()->public->host,
        'REDIS_PORT' => '25061',
        'REDIS_PASSWORD' => $database->settings->secret("{$prefix}_PASSWORD"),
    ]);
})->with('sentinel engines');

it('reads sentinel roles and maps its scripts', function (string $engine) {
    $driver = Database::providerFor($engine);

    expect($driver->role("role:master\nversion:8.1.0"))->toBe(NodeRole::Primary)
        ->and($driver->role("role:slave\nmaster_link_status:up\nmaster_sync_in_progress:0\n"))->toBe(NodeRole::Replica)
        ->and($driver->role("role:slave\nmaster_link_status:up\n"))->toBe(NodeRole::Unknown)
        ->and($driver->role("role:slave\nmaster_link_status:down\nmaster_sync_in_progress:0\n"))->toBe(NodeRole::Unknown)
        ->and($driver->role("role:slave\nmaster_link_status:up\nmaster_sync_in_progress:1\n"))->toBe(NodeRole::Unknown)
        ->and($driver->role('role:slave'))->toBe(NodeRole::Unknown)
        ->and(view($driver->scriptView('lifecycle.status'), engineScript($engine))->render())->toContain('master_sync_in_progress')
        ->and($driver->role('Could not connect'))->toBe(NodeRole::Unknown)
        ->and(Database::providerFor($engine)->scriptView('lifecycle.reset'))->toStartWith("ssh.engines.{$engine}.lines.")->toEndWith('.lifecycle.reset')
        ->and(Database::providerFor($engine)->scriptView('cluster.failover'))->toStartWith("ssh.engines.{$engine}.lines.")->toEndWith('.cluster.failover')
        ->and(Database::providerFor($engine)->failoverHint())->toContain('Sentinel');
})->with('sentinel engines');

it('connects without a ca by default', function (string $engine) {
    $profile = Database::providerFor($engine)->connection();

    expect($profile->command)->toStartWith("{$engine}-cli --tls --insecure -u rediss://")->not->toContain('{ca}')
        ->and($profile->envNote)->toContain("'context' => ['stream' => ['verify_peer' => false, 'verify_peer_name' => false]]");
})->with('sentinel engines');

it('explains how to verify with the ca', function (string $engine) {
    $profile = Database::providerFor($engine)->connection();

    expect($profile->commandNote)->toContain('replace --insecure with --cacert {ca}')
        ->and($profile->envNote)->toContain("'context' => ['stream' => ['cafile' => '/path/to/{ca}']]");
})->with('sentinel engines');

it('drops the ca file from the command and env when serving a public certificate', function (string $engine) {
    $profile = withZone(idleDatabase($engine, 1, DatabaseMode::Single))->provider()->connection();

    expect($profile->command)->toStartWith(($engine === 'valkey' ? 'valkey-cli' : 'redis-cli').' --tls -u rediss://')->not->toContain('{ca}')
        ->and($profile->envNote)->toBeNull()
        ->and($profile->commandNote)->toBeNull();
})->with('sentinel engines');

it('restarts a crashed clustered server only after sentinel had time to promote a replica', function (string $engine) {
    $single = idleDatabase($engine, 1, DatabaseMode::Single, 'single');
    $cluster = idleDatabase($engine, 3, name: 'cluster');

    $restartsOf = fn ($database): array => collect($database->provider()->processes()->toArray()['processes'])->pluck('restartSec', 'name')->all();

    expect($restartsOf($single))->toBe(["databasify-{$engine}" => '3s'])
        ->and($restartsOf($cluster))->toBe(["databasify-{$engine}" => '15s', "databasify-{$engine}-sentinel" => '3s']);
})->with('sentinel engines');

it('reloads certificates only into a running server, so a stopped member cannot fail a join', function (string $engine) {
    $database = idleDatabase($engine, 2);
    $script = view("ssh.engines.{$engine}.config.reload-tls", $database->provider()->scriptData()->for())->render();

    expect($script)->toContain("if databasify ctl is-active --quiet databasify-{$engine}; then\n    vcli CONFIG SET tls-cert-file")
        ->toContain('it loads the new certificates when it starts');
})->with('sentinel engines');

it('recovers through the generic path: quorum without script data, offsets from the last snapshot, a newer sentinel epoch on the survivor', function (string $engine) {
    $database = idleDatabase($engine, 3);
    $node = $database->nodes()->get()[1];
    $driver = Database::providerFor($engine);
    $render = fn (string $name): string => view(Database::providerFor($engine)->scriptView($name), [...$database->provider()->scriptData()->for($node), 'instance' => $driver->clientAddress($node)])->render();

    expect(view(Database::providerFor($engine)->scriptView('cluster.quorum'), engineScript($engine))->render())->toContain('for candidate in valkey redis; do')->toContain('echo "online=$total"')
        ->and($render('cluster.recovery-position'))->toContain("/{$engine}-check-rdb /srv/{$engine}/data/dump.rdb")
        ->and($render('cluster.force-quorum'))->toContain('sentinel already serves from $primary, nothing to force')
        ->toContain("sentinel monitor main {$node->privateIp()} ")
        ->toContain('sentinel current-epoch %s')
        ->and($render('cluster.rejoin'))->toContain('held back from promotion until its first full sync finishes')->toContain('rejoined as a replica of $primary')
        ->and($render('lifecycle.wake'))->toContain("databasify ctl stop databasify-{$engine}-sentinel ")->toContain("databasify ctl kill -s KILL databasify-{$engine} ")->toContain("databasify ctl stop databasify-{$engine} ")->toContain('stopped without saving');
})->with('sentinel engines');

it('puts the kernel back to its defaults when erasing a node, so the server is clean for the next database', function (string $engine) {
    $script = view("ssh.engines.{$engine}.lifecycle.cleanup", idleDatabase($engine, 1, DatabaseMode::Single, 'solo')->provider()->scriptData()->for())->render();

    expect($script)->toContain("/etc/sysctl.d/99-{$engine}.conf")
        ->toContain('sysctl -q -p "$originals"')
        ->toContain('echo "${original:-madvise}" > "/sys/kernel/mm/transparent_hugepage/$setting"');
})->with('sentinel engines');

it('gives transparent huge pages back the modes the server had before the first tuning, like always on debian', function (string $engine) {
    $root = sys_get_temp_dir().'/thp-'.uniqid();
    foreach (['bin', 'sys', 'etc/sysctl.d', 'etc/systemd/system'] as $directory) {
        mkdir("{$root}/{$directory}", 0755, true);
    }
    foreach (['systemctl', 'sysctl'] as $tool) {
        file_put_contents("{$root}/bin/{$tool}", "#!/bin/sh\nexit 0\n");
        chmod("{$root}/bin/{$tool}", 0755);
    }
    file_put_contents("{$root}/sys/enabled", "[always] madvise never\n");
    file_put_contents("{$root}/sys/defrag", "always defer defer+madvise [madvise] never\n");
    $data = idleDatabase($engine, 1, DatabaseMode::Single, 'solo')->provider()->scriptData()->for();
    $run = fn (string $script) => Process::env(['PATH' => "{$root}/bin:".getenv('PATH')])
        ->input("dir={$root}/srv/{$engine}\n".str_replace(['/sys/kernel/mm/transparent_hugepage', '/etc/'], ["{$root}/sys", "{$root}/etc/"], view("ssh.engines.{$engine}.{$script}", $data)->render()))
        ->run(['bash', '-euo', 'pipefail'])->throw();

    $run('config.host-tuning');
    file_put_contents("{$root}/sys/enabled", "always madvise [never]\n");
    $run('config.host-tuning');
    $originals = (string) file_get_contents("{$root}/etc/databasify/thp-originals");
    $run('lifecycle.cleanup');
    $restored = [file_get_contents("{$root}/sys/enabled"), file_get_contents("{$root}/sys/defrag"), is_file("{$root}/etc/databasify/thp-originals")];
    Process::run(['rm', '-rf', $root]);

    expect($originals)->toBe("enabled=always\ndefrag=madvise\n")
        ->and($restored)->toBe(["always\n", "madvise\n", false]);
})->with('sentinel engines');

it('records the kernel settings it tunes, never lowers somaxconn, and puts the originals back when erased', function (string $engine, string $somaxconn, string $tuned) {
    $root = sys_get_temp_dir().'/sentinel-sysctl-'.uniqid();
    foreach (['bin', 'kernel', 'sys', 'etc/sysctl.d', 'etc/systemd/system'] as $directory) {
        mkdir("{$root}/{$directory}", 0755, true);
    }
    file_put_contents("{$root}/bin/systemctl", "#!/bin/sh\nexit 0\n");
    chmod("{$root}/bin/systemctl", 0755);
    file_put_contents("{$root}/kernel/vm.overcommit_memory", "0\n");
    file_put_contents("{$root}/kernel/net.core.somaxconn", "{$somaxconn}\n");
    $sysctl = <<<'BASH'
        sysctl() {
            case "$1" in
                -n) cat "$root/kernel/$2" ;;
                -q) while IFS=' =' read -r key value; do echo "$value" > "$root/kernel/$key"; done < "$3" ;;
                --system) for file in "$root"/etc/sysctl.d/99-*.conf; do [ -f "$file" ] && while IFS=' =' read -r key value; do echo "$value" > "$root/kernel/$key"; done < "$file"; done; true ;;
            esac
        }
        BASH;
    $data = idleDatabase($engine, 1, DatabaseMode::Single, 'solo')->provider()->scriptData()->for();
    $run = fn (string $script) => Process::env(['PATH' => "{$root}/bin:".getenv('PATH')])
        ->input("root={$root}\ndir={$root}/srv/{$engine}\n{$sysctl}\n".str_replace(['/sys/kernel/mm/transparent_hugepage', '/etc/', '/proc/sys/vm/', '/proc/sys/net/core/'], ["{$root}/sys", "{$root}/etc/", "{$root}/kernel/vm.", "{$root}/kernel/net.core."], view("ssh.engines.{$engine}.{$script}", $data)->render()))
        ->run(['bash', '-euo', 'pipefail'])->throw();
    $kernel = fn (): array => [trim((string) file_get_contents("{$root}/kernel/vm.overcommit_memory")), trim((string) file_get_contents("{$root}/kernel/net.core.somaxconn"))];

    $run('config.host-tuning');
    $run('config.host-tuning');
    $whileTuned = $kernel();
    $recorded = is_file("{$root}/etc/databasify/sysctl-originals/{$engine}");
    $run('lifecycle.cleanup');
    $restored = $kernel();
    $leftovers = file_exists("{$root}/etc/databasify") || file_exists("{$root}/etc/sysctl.d/99-{$engine}.conf");
    Process::run(['rm', '-rf', $root]);

    expect($whileTuned)->toBe(['1', $tuned])
        ->and($recorded)->toBeTrue()
        ->and($restored)->toBe(['0', $somaxconn])
        ->and($leftovers)->toBeFalse();
})->with(['valkey', 'redis'])->with([
    'ubuntu default 4096' => ['4096', '4096'],
    'low default 128' => ['128', '1024'],
]);

it('keeps the replicas required for writes in the config file, so a restarted member still refuses writes when isolated', function (string $engine) {
    $database = idleDatabase($engine, 3);
    $script = view("ssh.engines.{$engine}.cluster.membership", [...$database->provider()->scriptData()->for(), ...$database->provider()->membershipVariables()])->render();

    expect($script)->toContain('vcli CONFIG SET min-replicas-to-write 1')
        ->toContain("sed -i 's/^min-replicas-to-write .*/min-replicas-to-write 1/' \"\$conf\"");
})->with('sentinel engines');

it('refuses an empty primary and holds back a replica without data only when it restarts, so live settings still apply', function (string $engine) {
    $database = idleDatabase($engine, 2);
    [$seed] = $database->nodes()->get()->all();
    $reader = withReadOnlyNode($database);
    $single = idleDatabase($engine, 1, DatabaseMode::Single, 'solo');
    $render = fn ($database, $node): string => view(Database::providerFor($engine)->scriptView('lifecycle.start'), $database->provider()->scriptData()->starting($node, bootstrapsCluster: false))->render();

    expect(Database::providerFor($engine)->scriptView('lifecycle.start'))->toStartWith("ssh.engines.{$engine}.lines.")->toEndWith('.lifecycle.start')
        ->and($render($database->fresh() ?? $database, $seed))->toContain("if [ -s \"\$start_refused\" ]; then\n    cat \"\$start_refused\"\n    exit 1")
        ->toContain("if grep -q '^replicaof ' \"\$conf\" && restart_loses_data; then\n    echo 'replica-priority 0' >> \"\$conf\"")
        ->toContain("restart_loses_data() { grep -q '^appendonly no\$' \"\$conf\" || ! has_data; }")
        ->toContain('databasify ctl restart "$process"')
        ->and($render($database->fresh() ?? $database, $reader))->not->toContain('start_refused')
        ->and($render($single, $single->seed()))->not->toContain('start_refused')->toContain('databasify ctl restart "$process"');
})->with('sentinel engines');

it('supervises the server in the container and, once clustered, sentinel after it, both stopped gracefully', function (string $engine) {
    $single = idleDatabase($engine, 1, DatabaseMode::Single, 'single');
    $cluster = idleDatabase($engine, 3, name: 'cluster');
    $processesOf = fn ($database): array => $database->provider()->processes()->toArray()['processes'];

    expect($processesOf($single))->toHaveCount(1)
        ->and($processesOf($single)[0])->toMatchArray([
            'name' => "databasify-{$engine}",
            'user' => $engine,
            'restart' => 'always',
            'stopSignal' => 'SIGTERM',
            'stopTimeout' => '2m',
            'argv' => ['sh', '-c', 'umask 0027 && exec "$@"', 'private-umask', "/usr/local/bin/{$engine}-server", "/srv/{$engine}/conf/{$engine}.conf"],
            'enabled' => false,
        ])
        ->and($processesOf($cluster)[1])->toMatchArray([
            'name' => "databasify-{$engine}-sentinel",
            'after' => ["databasify-{$engine}"],
            'stopTimeout' => '10s',
            'argv' => ['sh', '-c', 'umask 0027 && exec "$@"', 'private-umask', "/usr/local/bin/{$engine}-server", "/srv/{$engine}/sentinel/sentinel.conf", '--sentinel'],
        ]);
})->with('sentinel engines');

it('keeps the config files private after the engine and sentinel rewrite them', function (string $engine) {
    $database = idleDatabase($engine, 3);
    $processes = $database->provider()->processes()->toArray()['processes'];
    $prepare = view("ssh.engines.{$engine}.lifecycle.prepare", $database->provider()->scriptData()->deploy($database->seed()))->render();

    expect(array_slice($processes[0]['argv'], 2, 2))->toBe(['umask 0027 && exec "$@"', 'private-umask'])
        ->and(array_slice($processes[1]['argv'], 2, 2))->toBe(['umask 0027 && exec "$@"', 'private-umask'])
        ->and($prepare)->toContain("install -d -m 750 -o {$engine} -g {$engine} \"\$dir/conf\" \"\$dir/data\" \"\$dir/sentinel\"");
})->with('sentinel engines');

it('hands the data and sentinel directories to the engine user by name before the server starts, and no longer asks for systemd supervision', function (string $engine) {
    $database = idleDatabase($engine, 3);
    $prepare = view("ssh.engines.{$engine}.lifecycle.prepare", $database->provider()->scriptData()->deploy($database->seed()))->render();

    expect($prepare)->toContain("install -d -m 750 -o {$engine} -g {$engine} \"\$dir/conf\" \"\$dir/data\" \"\$dir/sentinel\"")
        ->and(renderedFile($database, "{$engine}.conf"))->toContain("supervised no\n")->not->toContain('systemd')
        ->and(renderedFile($database, 'sentinel.conf'))->toContain("supervised no\n")->not->toContain('systemd');
})->with('sentinel engines');

it('controls processes through the wrapper in every script that runs inside the container', function (string $engine) {
    $database = idleDatabase($engine, 3);
    $node = $database->nodes()->get()[1];
    $driver = Database::providerFor($engine);
    $data = [...$database->provider()->scriptData()->readiness($node, expectPrimary: false), ...$database->provider()->scriptData()->starting($node, bootstrapsCluster: false), ...$database->provider()->membershipVariables(), 'instance' => $driver->clientAddress($node),
        'peers' => 2];
    $scripts = collect(['lifecycle.start', 'lifecycle.wait-ready', 'cluster.cluster', 'cluster.uncluster', 'config.reload-tls', 'cluster.recovery-position', 'cluster.force-quorum', 'cluster.rejoin', 'cluster.failover', 'lifecycle.reset', 'cluster.membership'])
        ->mapWithKeys(fn (string $name): array => [$name => view(Database::providerFor($engine)->scriptView($name), $data)->render()]);

    expect($scripts->filter(fn (string $script): bool => preg_match('/\b(systemctl|journalctl|systemd-run)\b/', $script) === 1)->keys()->all())->toBe([])
        ->and($scripts['cluster.cluster'])->toContain("databasify ctl enable --now databasify-{$engine}-sentinel")
        ->and($scripts['cluster.uncluster'])->toContain("databasify ctl disable --now databasify-{$engine}-sentinel")
        ->and($scripts['lifecycle.wait-ready'])->toContain("databasify ctl logs --unit databasify-{$engine} --lines 20")
        ->and($scripts['cluster.rejoin'])->toContain("databasify ctl start databasify-{$engine}\n");
})->with('sentinel engines');

it('reloads certificates and reads metrics inside the container with only the engine directory', function (string $engine) {
    $database = idleDatabase($engine, 2);
    $refresh = view('ssh.databases.certificates.refresh', [...$database->provider()->scriptData()->for(), 'files' => [], 'reload' => Database::providerFor($engine)->scriptView('config.reload-tls')])->render();
    $metrics = view('ssh.databases.monitoring.metrics', [...$database->provider()->scriptData()->for(), 'engineHelpers' => Database::providerFor($engine)->scriptView('helpers'), 'engineMetrics' => Database::providerFor($engine)->scriptView('monitoring.metrics')])->render();

    expect($refresh)->toContain("docker exec -i databasify-{$engine} databasify step reload-tls <<'RELOAD'")
        ->toContain("if databasify ctl is-active --quiet databasify-{$engine}; then")
        ->and($metrics)->toContain("conf=/srv/{$engine}/conf/{$engine}.conf")->toContain('vcli INFO keyspace')
        ->and(bashErrorsIn($refresh))->toBe('')
        ->and(bashErrorsIn($metrics))->toBe('');
})->with('sentinel engines');

it('turns transparent huge pages off on the host after tuned and before docker starts the container', function (string $engine) {
    $script = view("ssh.engines.{$engine}.config.host-tuning", idleDatabase($engine, 1, DatabaseMode::Single, 'solo')->provider()->scriptData()->for())->render();

    expect($script)->toContain("After=tuned.service\nBefore=docker.service\n")
        ->toContain("/etc/sysctl.d/99-{$engine}.conf")
        ->toContain('systemctl enable --now disable-thp.service');
})->with('sentinel engines');

it('moves a replica that ended up behind another replica to the primary sentinel serves from before calling it ready', function (string $engine) {
    $database = idleDatabase($engine, 3);
    [, $standby] = $database->nodes()->get()->all();
    $single = idleDatabase($engine, 1, DatabaseMode::Single, 'solo');
    $render = fn ($database, $node): string => view("ssh.engines.{$engine}.lifecycle.wait-ready", $database->provider()->scriptData()->readiness($node, expectPrimary: false))->render();

    expect($render($database, $standby))->toContain('grep -q \'^master_link_status:up\' <<< "$info" && follows_the_primary "$info"')
        ->toContain('primary=$(scli SENTINEL get-master-addr-by-name main | head -n 1)')
        ->toContain("[ \"\$primary\" = '{$standby->privateIp()}' ]")
        ->toContain('vcli REPLICAOF "$primary" 6379 > /dev/null')
        ->toContain('sed -i "s/^replicaof .*/replicaof $primary 6379/" "$conf"')
        ->and($render($single, $single->seed()))->not->toContain('SENTINEL')
        ->and(bashErrorsIn($render($database, $standby)))->toBe('');
})->with('sentinel engines');

it('hands every valkey script the flavor variables', function () {
    expect(Database::providerFor('valkey')->scriptVariables())->toBe([
        'flavor' => 'valkey',
        'cli' => '/usr/local/bin/valkey-cli',
        'checkRdb' => '/usr/local/bin/valkey-check-rdb',
        'versionField' => 'valkey_version',
        'serverUnit' => 'databasify-valkey',
        'sentinelUnit' => 'databasify-valkey-sentinel',
        'dataPort' => 6379,
        'sentinelPort' => 26379,
        'master' => 'main',
        'snapshot' => '3600 1',
    ]);
});

it('waits for the initial append only file after a sync before calling a node ready or handing the primary off', function (string $engine) {
    $database = idleDatabase($engine, 3);
    [, $standby] = $database->nodes()->get()->all();
    $driver = Database::providerFor($engine);
    $data = [...$database->provider()->scriptData()->readiness($standby, expectPrimary: false), ...$database->provider()->scriptData()->for()];
    $waitReady = view($driver->scriptView('lifecycle.wait-ready'), $data)->render();
    $failover = view($driver->scriptView('cluster.failover'), $data)->render();

    expect($waitReady)->toContain("aof_written() { local info; info=\$(vcli INFO persistence); grep -q '^aof_rewrite_in_progress:0\$' <<< \"\$info\" && grep -q '^aof_rewrite_scheduled:0\$' <<< \"\$info\"; }")
        ->toContain('synced && aof_written')
        ->and($failover)->toContain("settled() { grep -q '^master_link_status:up' <<< \"\$(vcli INFO replication)\" && aof_written; }")
        ->toContain('wait_for 300 settled || echo "still syncing from the new primary"')
        ->and(bashErrorsIn($waitReady))->toBe('')
        ->and(bashErrorsIn($failover))->toBe('');
})->with('sentinel engines');

it('keeps an append only file synced every second', function (string $engine) {
    $config = renderedFile(idleDatabase($engine, 3), "{$engine}.conf");

    expect($config)->toContain("appendonly yes\n")->toContain("appendfsync everysec\n")->toContain("aof-use-rdb-preamble yes\n");
})->with('sentinel engines');

it('loads the modules the redis image bundles, as the official image does', function () {
    $modules = ['rejson', 'redisearch', 'redisbloom', 'redistimeseries'];
    $redis = renderedFile(idleDatabase('redis', 1, DatabaseMode::Single), 'redis.conf');
    $valkey = renderedFile(idleDatabase('valkey', 1, DatabaseMode::Single, 'other'), 'valkey.conf');

    foreach ($modules as $module) {
        expect($redis)->toContain("loadmodule /usr/local/lib/redis/modules/{$module}.so\n");
    }

    expect($valkey)->not->toContain('loadmodule');
});

it('waits for a running aof rewrite before handing off', function (string $engine, string $flavorName, string $rewriting, int $exitCode) {
    $root = sys_get_temp_dir().'/aof-'.uniqid();
    mkdir("{$root}/bin", 0755, true);
    mkdir("{$root}/conf", 0755, true);
    file_put_contents("{$root}/conf/password", "secret\n");
    $data = [...Database::providerFor($engine)->scriptVariables(), 'directory' => $root, 'cli' => "{$root}/bin/cli"];
    file_put_contents($data['cli'], "#!/bin/sh\nprintf 'aof_enabled:1\\r\\naof_rewrite_in_progress:{$rewriting}\\r\\naof_rewrite_scheduled:0\\r\\n'\n");
    file_put_contents("{$root}/bin/timeout", "#!/bin/sh\nshift\nexec \"\$@\"\n");
    chmod($data['cli'], 0755);
    chmod("{$root}/bin/timeout", 0755);
    $result = Process::env(['PATH' => "{$root}/bin:".getenv('PATH')])->input(view("ssh.engines.{$engine}.helpers", $data)->render()."\naof_written\n")->run(['bash', '-uo', 'pipefail']);
    Process::run(['rm', '-rf', $root]);

    expect($result->exitCode())->toBe($exitCode);
})->with('sentinel engines')->with([
    'rewriting' => ['1', 1],
    'unanswered' => ['', 1],
    'idle' => ['0', 0],
]);

it('drops the connections of a removed node that could not be stopped before sentinel forgets it', function (string $engine) {
    $root = sys_get_temp_dir().'/reset-'.uniqid();
    mkdir("{$root}/bin", 0755, true);
    mkdir("{$root}/conf", 0755, true);
    file_put_contents("{$root}/conf/password", "secret\n");
    $data = [...Database::providerFor($engine)->scriptVariables(), 'directory' => $root, 'cli' => "{$root}/bin/cli", 'processes' => [], 'peers' => 1, 'hosts' => '10.0.0.1,10.0.0.2'];
    file_put_contents($data['cli'], <<<SH
        #!/bin/bash
        echo "\$*" >> {$root}/calls
        case "\$*" in
            *'-p 6379 CLIENT LIST') printf 'id=1 addr=10.0.0.2:41 laddr=10.0.0.1:6379 name=\\nid=2 addr=10.0.0.9:42 laddr=10.0.0.1:6379 name=\\nid=3 addr=203.0.113.5:43 laddr=10.0.0.1:25061 name=\\nid=4 addr=127.0.0.1:44 laddr=127.0.0.1:6379 name=\\n' ;;
            *'-p 26379 CLIENT LIST') printf 'id=5 addr=10.0.0.9:51 laddr=10.0.0.1:26379 name=\\nid=6 addr=10.0.0.2:52 laddr=10.0.0.1:26379 name=\\n' ;;
            *'SENTINEL sentinels main') printf 'name\\nb\\nip\\n10.0.0.2\\n' ;;
        esac
        SH);
    file_put_contents("{$root}/bin/timeout", "#!/bin/sh\nshift\nexec \"\$@\"\n");
    chmod($data['cli'], 0755);
    chmod("{$root}/bin/timeout", 0755);
    $result = Process::env(['PATH' => "{$root}/bin:".getenv('PATH')])->input(view("ssh.engines.{$engine}.lifecycle.reset", $data)->render())->run(['bash', '-euo', 'pipefail']);
    $calls = (string) file_get_contents("{$root}/calls");
    Process::run(['rm', '-rf', $root]);

    expect($result->exitCode())->toBe(0)
        ->and($calls)->toContain("-p 6379 CLIENT KILL ID 2\n")->toContain("-p 26379 CLIENT KILL ID 5\n")->toContain("-p 26379 SENTINEL RESET main\n")
        ->not->toContain('KILL ID 1')->not->toContain('KILL ID 3')->not->toContain('KILL ID 4')->not->toContain('KILL ID 6');
})->with('sentinel engines');
