<?php

declare(strict_types=1);

use App\Actions\Databases\Network\ApplyNodeFirewall;
use App\Actions\Databases\Network\RemoveNodeFirewall;
use App\DTOs\Databases\NodeFile;
use App\Enums\Databases\DatabaseMode;
use App\Models\Database;
use App\Support\Ssh\CertificateOutput;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

it('gives every script the engine directory, its container, its processes and flavor variables', function () {
    $valkey = idleDatabase('valkey', 1, DatabaseMode::Single, 'cache');
    $mysql = idleDatabase('mysql', 1, DatabaseMode::Single, 'db');

    expect($valkey->provider()->scriptData()->for())->toMatchArray(['directory' => '/srv/valkey', 'container' => 'databasify-valkey', 'repository' => 'ghcr.io/databasifylabs/databasify-valkey', 'processes' => ['databasify-valkey'], 'flavor' => 'valkey', 'cli' => '/usr/local/bin/valkey-cli', 'clustered' => false])
        ->and($mysql->provider()->scriptData()->for($mysql->seed()))->toMatchArray(['directory' => '/srv/mysql', 'processes' => ['mysql'], 'privateIp' => $mysql->seed()->privateIp(), 'readOnly' => false, 'seed' => true])
        ->and($mysql->provider()->scriptData()->readiness($mysql->seed(), expectPrimary: true))->toMatchArray(['expectPrimary' => true, 'clientPort' => 25060, 'deadline' => 21000]);
});

it('deploys a node with its image, container, rendered files, process manifest and certificates', function () {
    withImage('valkey', '9.1.2', 'sha256:'.str_repeat('a', 64));
    $database = idleDatabase('valkey', 1, DatabaseMode::Single, 'cache');
    $database->update(['settings' => $database->settings->withCertificates(['ca.crt' => 'CA', 'server.pem' => 'PEM'])]);
    $database = $database->fresh() ?? $database;

    $deploy = $database->provider()->scriptData()->deploy($database->seed(), fresh: true);

    expect($deploy)->toMatchArray([
        'directory' => '/srv/valkey',
        'prepare' => 'ssh.engines.valkey.lines.9.lifecycle.prepare',
        'hostTuning' => 'ssh.engines.valkey.lines.9.config.host-tuning',
        'fresh' => true,
        'databaseKey' => $database->dataKey(),
        'line' => '9',
        'container' => 'databasify-valkey',
        'image' => 'ghcr.io/databasifylabs/databasify-valkey@sha256:'.str_repeat('a', 64),
    ])
        ->and($deploy['run'])->toStartWith('docker run -d --name databasify-valkey --network host')
        ->and(collect($deploy['files'])->map(fn (NodeFile $file): string => "{$file->path} {$file->mode} {$file->owner}")->all())->toBe([
            '/srv/valkey/conf/valkey.conf 640 valkey',
            '/srv/valkey/conf/password 600 root',
            '/srv/valkey/conf/processes.json 640 root',
            '/srv/valkey/tls/ca.crt 600 valkey',
            '/srv/valkey/tls/server.pem 600 valkey',
        ]);
});

it('gives read-only nodes their own certificates when they have them', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $reader = withReadOnlyNode($database);
    $plainReader = withReadOnlyNode($database);
    $database->update(['settings' => $database->settings->withCertificates(['ca.crt' => 'CA', 'server.pem' => 'PEM'])->withNodeCertificates($reader->id, ['server.pem' => 'NODE-PEM'])]);

    expect($database->fresh()?->certificateFilesFor($reader))->toBe(['ca.crt' => 'CA', 'server.pem' => 'NODE-PEM'])
        ->and($database->fresh()?->certificateFilesFor($plainReader))->toBe(['ca.crt' => 'CA', 'server.pem' => 'PEM']);
});

it('reuses the certificate authority only when asked', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $database->update(['settings' => $database->settings->withCertificates(['ca.crt' => 'CA', 'ca.key' => 'KEY', 'server.pem' => 'PEM'])]);
    $database = $database->fresh() ?? $database;

    expect($database->provider()->scriptData()->certificates(['10.0.0.1', 'cache.example.com']))->toBe(['authority' => [], 'authorityName' => 'Valkey CA', 'serverName' => 'valkey-server', 'subjectAltNames' => 'IP:10.0.0.1,DNS:cache.example.com', 'rsa' => false, 'combined' => true])
        ->and($database->provider()->scriptData()->certificates(['10.0.0.1'], reuseAuthority: true)['authority'])->toBe(['ca.crt' => 'CA', 'ca.key' => 'KEY']);
});

it('opens client and peer ports and the private network of the server', function () {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    $seed = $database->seed();
    $table = 'databasify_database_'.str_replace('-', '', $database->id);
    $ssh = fakeSsh();

    resolve(ApplyNodeFirewall::class)->handle($database, $seed, ['203.0.113.7/32', '203.0.113.7/32', '10.0.0.0/8']);

    $seed->server->update(['network_id' => null]);
    $seed->server->unsetRelation('network');

    resolve(ApplyNodeFirewall::class)->handle($database, $seed, []);
    resolve(RemoveNodeFirewall::class)->handle($database, $seed);

    expect($ssh->scriptAt(0))->toContain("table={$table}")->toContain("elements = { %s } }\\n' '203.0.113.7/32, 10.0.0.0/8'")->toContain("vpc_subnet='10.0.0.0/8'")
        ->and($ssh->scriptAt(1))->toContain("vpc_subnet=''")
        ->and($ssh->scriptAt(2))->toContain("table={$table}")->toContain('nft delete table inet "$table"')->not->toContain('vpc_subnet=');
});

it('decodes certificate lines and ignores everything else', function () {
    expect(CertificateOutput::parse(certificateOutput('ca.crt', 'server.pem')."noise\nCERT broken !!!\n"))
        ->toBe(['ca.crt' => 'ca.crt-content', 'server.pem' => 'server.pem-content', 'broken' => '']);
});

/**
 * @return array{root: string, run: Closure(string): mixed, tuning: string, reset: string, swappiness: Closure(): string}
 */
function sysctlSandbox(string $engine, string $tuningFile): array
{
    $root = sys_get_temp_dir().'/sysctl-'.uniqid();
    mkdir("{$root}/kernel", recursive: true);
    mkdir("{$root}/srv/{$engine}", recursive: true);
    file_put_contents("{$root}/kernel/vm.swappiness", "60\n");
    file_put_contents("{$root}/kernel/net.ipv4.tcp_keepalive_time", "7200\n");
    $sysctl = <<<'BASH'
        sysctl() {
            case "$1" in
                -n) cat "$root/kernel/$2" 2> /dev/null || echo 0 ;;
                -q) while IFS=' =' read -r key value; do echo "$value" > "$root/kernel/$key"; done < "$3" ;;
                --system) for file in "$root"/99-*.conf; do [ -f "$file" ] && while IFS=' =' read -r key value; do echo "$value" > "$root/kernel/$key"; done < "$file"; done; true ;;
            esac
        }
        BASH;
    $local = fn (string $script): string => str_replace([$tuningFile, '/proc/sys/', '/etc/databasify'], ["{$root}/".basename($tuningFile), "{$root}/kernel/", "{$root}/etc"], $script);

    return [
        'root' => $root,
        'run' => fn (string $script) => Process::input("set -euo pipefail\nroot={$root}\ndir={$root}/srv/{$engine}\n{$sysctl}\n{$script}")->run(['bash'])->throw(),
        'tuning' => $local(view(Database::providerFor($engine)->scriptView('config.host-tuning'))->render()),
        'reset' => $local("rm -f {$tuningFile}\n".view('ssh.databases.host-tuning.reset')->render()),
        'swappiness' => fn (): string => trim((string) file_get_contents("{$root}/kernel/vm.swappiness")),
    ];
}

it('records the kernel settings it tunes once and puts them back when the database is erased', function (string $engine, string $tuningFile) {
    $database = idleDatabase($engine, 1, DatabaseMode::Single);
    $sandbox = sysctlSandbox($engine, $tuningFile);

    $sandbox['run']($sandbox['tuning']);
    $sandbox['run']($sandbox['tuning']);
    $tuned = $sandbox['swappiness']();
    $recorded = file_exists("{$sandbox['root']}/etc/sysctl-originals/{$engine}");
    File::deleteDirectory("{$sandbox['root']}/srv/{$engine}");
    $sandbox['run']($sandbox['reset']);

    expect($tuned)->toBe('1')
        ->and($recorded)->toBeTrue()
        ->and($sandbox['swappiness']())->toBe('60')
        ->and(trim((string) file_get_contents("{$sandbox['root']}/kernel/net.ipv4.tcp_keepalive_time")))->toBe('7200')
        ->and(file_exists("{$sandbox['root']}/etc"))->toBeFalse()
        ->and(view('ssh.databases.nodes.stop', $database->provider()->scriptData()->stopping(true))->render())
        ->toContain($tuningFile)->toContain('sysctl -q -p "$originals"');
    File::deleteDirectory($sandbox['root']);
})->with([
    'mysql' => ['mysql', '/etc/sysctl.d/99-mysql.conf'],
    'mariadb' => ['mariadb', '/etc/sysctl.d/99-mysql.conf'],
    'postgres' => ['postgres', '/etc/sysctl.d/99-postgres.conf'],
    'mongodb' => ['mongodb', '/etc/sysctl.d/99-mongodb.conf'],
]);

it('never lowers a max_map_count the server already set higher for MongoDB', function () {
    $sandbox = sysctlSandbox('mongodb', '/etc/sysctl.d/99-mongodb.conf');
    file_put_contents("{$sandbox['root']}/kernel/vm.max_map_count", "1048576\n");

    $sandbox['run']($sandbox['tuning']);
    $sandbox['run']($sandbox['tuning']);

    expect(trim((string) file_get_contents("{$sandbox['root']}/kernel/vm.max_map_count")))->toBe('1048576');
    File::deleteDirectory($sandbox['root']);
});
