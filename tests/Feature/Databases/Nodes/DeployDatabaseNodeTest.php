<?php

declare(strict_types=1);

use App\Actions\Databases\Nodes\DeployDatabaseNode;
use App\Enums\Databases\DatabaseMode;

it('refuses fresh deploys onto another database data and marks its own', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $fresh = view('ssh.databases.nodes.deploy', $database->provider()->scriptData()->deploy($database->seed(), fresh: true))->render();
    $redeploy = view('ssh.databases.nodes.deploy', $database->provider()->scriptData()->deploy($database->seed()))->render();

    expect($fresh)->toContain('holds data from another database')->toContain("echo '{$database->dataKey()}' > \"\$dir/.database\"")
        ->and($redeploy)->not->toContain('holds data from another database')->toContain("echo '{$database->dataKey()}' > \"\$dir/.database\"");
});

it('writes its files, runs the engine image and then prepares its data inside the container', function () {
    withImage('mysql', '8.4.13', 'sha256:'.str_repeat('b', 64));
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $script = view('ssh.databases.nodes.deploy', $database->provider()->scriptData()->deploy($database->seed()))->render();
    $image = 'ghcr.io/databasifylabs/databasify-mysql@sha256:'.str_repeat('b', 64);

    expect($script)->toContain(" 640 \"\$engine_uid\" \"\$engine_gid\"\n")
        ->toContain("put_file '/srv/mysql/conf/root.cnf' 600 0 0")
        ->toContain("put_file '/srv/mysql/conf/processes.json' 640 0 0")
        ->toContain('install -d -m 755 -o "$engine_uid" -g "$engine_gid" "$dir"')
        ->toContain("image='{$image}'")
        ->toContain('pull_image "$image"')
        ->toContain("running_image() {\n    { docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' \"\$1\" 2> /dev/null || true; } | sed -n 's/^DATABASIFY_IMAGE=//p'\n}")
        ->toContain('running_image=$(running_image databasify-mysql)')
        ->toContain("if [ -n \"\$running_image\" ] && [ \"\$running_image\" != \"\$image\" ]; then\n    docker stop databasify-mysql > /dev/null\n    docker rm -v databasify-mysql > /dev/null")
        ->toContain('    docker run -d --name databasify-mysql --network host --ipc host --security-opt label=disable --restart unless-stopped --stop-timeout 1050')
        ->toContain("    docker start databasify-mysql > /dev/null\n")
        ->toContain("    ctl daemon-reload\n")
        ->toContain("docker exec -i databasify-mysql databasify step prepare <<'PREPARE'\nset -euo pipefail\n")
        ->toContain('mysqld --defaults-file=/srv/mysql/conf/my.cnf --initialize-insecure --user=mysql --init-file="$dir/conf/init.sql"')
        ->not->toContain('apt-get install')
        ->and(strpos($script, "put_file '/srv/mysql/conf/init.sql'"))->toBeLessThan(strpos($script, 'docker run -d'))
        ->and(strpos($script, 'docker run -d'))->toBeLessThan(strpos($script, '--initialize-insecure'))
        ->and(bashErrorsIn($script))->toBe('');
});

it('takes the engine uid from the image, reserves it on the host and refuses images that run as another uid', function () {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    $script = view('ssh.databases.nodes.deploy', $database->provider()->scriptData()->deploy($database->seed()))->render();

    expect($script)->toContain("engine_ids=\$(image_ids \"\$image\" postgres)\nuse_engine_ids \"\$engine_ids\"\n")
        ->toContain('docker run --rm --network none --entrypoint sh "$1" -c \'echo "$(id -u "$1"):$(id -g "$1")"\' sh "$2"')
        ->toContain("if [ \"\$engine_uid\" != 64999 ] || [ \"\$engine_gid\" != 64999 ]; then\n    echo \"\$image runs postgres as \$engine_uid:\$engine_gid, but databasify engines run as 64999:64999; this image cannot be deployed\"\n    exit 1")
        ->toContain("    echo \"uid 64999 belongs to \$uid_holder on this server, the database engine needs it to itself\"\n    exit 1")
        ->toContain('[ -n "$gid_holder" ] || groupadd -r -K SYS_GID_MAX=64999 -g 64999 databasify-engine')
        ->toContain('[ -n "$uid_holder" ] || useradd -r -K SYS_UID_MAX=64999 -u 64999 -g 64999 -M -d /nonexistent -s /usr/sbin/nologin databasify-engine')
        ->not->toContain('chown -h')
        ->and(strpos($script, 'pull_image "$image"'))->toBeLessThan(strpos($script, 'image_ids "$image"'))
        ->and(strpos($script, 'useradd -r'))->toBeLessThan(strpos($script, "put_file '/srv/postgres/conf/postgresql.conf'"))
        ->and(bashErrorsIn($script))->toBe('');
});

it('removes the reserved engine user with the last database data on the server', function () {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    $erase = view('ssh.databases.nodes.stop', $database->provider()->scriptData()->stopping(true))->render();

    expect($erase)->toContain('for engine_dir in /srv/valkey /srv/redis /srv/mysql /srv/postgres /srv/mariadb /srv/mongodb; do')
        ->toContain("if [ \"\$(getent passwd 64999 | cut -d: -f1 || true)\" = databasify-engine ] && ! engine_data_left; then\n    userdel databasify-engine")
        ->and(strpos($erase, 'rm -rf "$dir"'))->toBeLessThan(strpos($erase, 'userdel'))
        ->and(view('ssh.databases.nodes.stop', $database->provider()->scriptData()->stopping(false))->render())->not->toContain('userdel')
        ->and(bashErrorsIn($erase))->toBe('');
});

it('refuses to deploy onto a server without docker', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $script = view('ssh.databases.nodes.deploy', $database->provider()->scriptData()->deploy($database->seed()))->render();

    expect($script)->toContain("if ! command -v docker > /dev/null 2>&1; then\n    echo \"Docker is not installed on this server, provision it again first.\"\n    exit 1\nfi")
        ->toContain('disable-thp.service');
});

it('configures, firewalls and starts a node in that order on its own server', function () {
    $database = idleDatabase('valkey', 2);
    $database->trustedSources()->create(['cidr' => '198.51.100.0/24']);
    [$seed, $standby] = $database->nodes()->get()->all();
    $ssh = fakeSsh();

    resolve(DeployDatabaseNode::class)->handle($database, $standby, fresh: true);

    expect(collect($ssh->runs)->pluck('server')->unique()->all())->toBe([$standby->server->name])
        ->and($ssh->runs)->toHaveCount(4)
        ->and($ssh->scriptAt(0))->toContain('holds data from another database')
        ->and(fileWrittenIn($ssh->scriptAt(0), '/srv/valkey/conf/valkey.conf'))->toContain("replicaof {$seed->privateIp()} 6379\n")
        ->and($ssh->scriptAt(1))->toContain('198.51.100.0/24')
        ->and($ssh->scriptAt(2))->toContain("processes='databasify-valkey databasify-valkey-sentinel'")->toContain('databasify ctl restart "$process"')
        ->and($ssh->scriptAt(3))->toContain('master_link_status:up');
});

it('makes sure a small server has swap before tuning the host for the database', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $script = view('ssh.databases.nodes.deploy', $database->provider()->scriptData()->deploy($database->seed()))->render();

    expect($script)->toContain('swapon --show --noheadings')
        ->toContain('[ "$memory_mb" -ge 4096 ]')
        ->toContain("grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab")
        ->and(strpos($script, 'fallocate -l 2G /swapfile'))->toBeLessThan(strpos($script, 'vm.swappiness = 1'));
});
