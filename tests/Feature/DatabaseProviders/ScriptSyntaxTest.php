<?php

declare(strict_types=1);

use App\DTOs\Databases\NodeFile;
use App\DTOs\Recovery\RecoverySource;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Servers\OsFamily;
use App\Models\Database;
use App\Services\Ssh\Ssh;
use Carbon\CarbonImmutable;

/**
 * @return array<string, string>
 */
function renderedScriptsOf(Database $database): array
{
    $engine = $database->provider();
    $seed = $database->seed();
    $settings = $database->settings;
    $render = fn (string $name, array $data): string => view($database->provider()->scriptView($name), $data)->render();

    return collect([
        'deploy' => fn (): string => view('ssh.databases.nodes.deploy', $database->provider()->scriptData()->deploy($seed, fresh: true))->render(),
        'stop' => fn (): string => view('ssh.databases.nodes.stop', $database->provider()->scriptData()->stopping(true))->render(),
        'keep' => fn (): string => view('ssh.databases.nodes.stop', $database->provider()->scriptData()->stopping(false))->render(),
        'pull' => fn (): string => view('ssh.databases.nodes.pull', [...$database->provider()->scriptData()->for(), 'image' => 'ghcr.io/databasifylabs/databasify-x@sha256:'.str_repeat('a', 64)])->render(),
        'prepare' => fn (): string => $render('lifecycle.prepare', $database->provider()->scriptData()->deploy($seed, fresh: true)),
        'start' => fn (): string => $render('lifecycle.start', $database->provider()->scriptData()->starting($seed, bootstrapsCluster: true)),
        'wait-ready' => fn (): string => $render('lifecycle.wait-ready', $database->provider()->scriptData()->readiness($seed, expectPrimary: true)),
        'status' => fn (): string => $render('lifecycle.status', $database->provider()->scriptData()->for($seed)),
        'metrics' => fn (): string => $render('helpers', $database->provider()->scriptData()->for($seed))."\n".$render('monitoring.metrics', $database->provider()->scriptData()->for($seed)),
        'cluster' => fn (): string => $render('cluster.cluster', $database->provider()->scriptData()->clustering($seed)),
        'uncluster' => fn (): string => $render('cluster.uncluster', $database->provider()->scriptData()->clustering($seed)),
        'admit' => fn (): string => $render('cluster.admit', $database->provider()->scriptData()->admission($seed)),
        'adopt' => fn (): string => $render('cluster.adopt', $database->provider()->scriptData()->admission($seed)),
        'apply-config' => fn (): string => $render('config.apply-config', $database->provider()->scriptData()->liveConfig($seed, array_map(fn ($field) => $field->key, $engine->fields()))),
        'reload-tls' => fn (): string => $render('config.reload-tls', $database->provider()->scriptData()->for($seed)),
        'failover' => fn (): string => $render('cluster.failover', [...$database->provider()->scriptData()->for(), 'target' => $engine->clientAddress($seed)]),
        'after-bootstrap' => fn (): string => $render('lifecycle.after-bootstrap', [...$database->provider()->scriptData()->for(), 'secrets' => $settings->secrets, 'schema' => 'defaultdb', 'username' => 'databasify', 'sql' => 'SELECT 1;']),
        'certificates' => fn (): string => view('ssh.databases.certificates.generate', $database->provider()->scriptData()->certificates(['10.0.0.1', 'db.example.com']))->render(),
    ])->except(stepsMissingFrom($database->engine))->map(fn (Closure $render): string => $render())->all();
}

/**
 * @return list<string>
 */
function stepsMissingFrom(string $engine): array
{
    return match ($engine) {
        'valkey', 'redis' => ['admit', 'adopt', 'after-bootstrap'],
        'mysql' => ['admit'],
        'mariadb' => ['adopt'],
        'mongodb' => ['cluster', 'admit'],
        default => [],
    };
}

it('renders every engine script as valid bash', function (string $engine, int $servers, string $line) {
    $database = idleDatabase($engine, $servers, $servers === 1 ? DatabaseMode::Single : DatabaseMode::HighAvailability, versionLine: $line);
    withReadOnlyNode($database);

    $errors = collect(renderedScriptsOf($database->fresh()))
        ->map(fn (string $script): string => bashErrorsIn($script))
        ->filter();

    expect($errors->all())->toBe([]);
})->with([
    'valkey 8' => ['valkey', 1, '8'],
    'valkey 9' => ['valkey', 1, '9'],
    'valkey 8 cluster' => ['valkey', 3, '8'],
    'valkey 9 cluster' => ['valkey', 3, '9'],
    'redis 8 cluster' => ['redis', 3, '8'],
    'postgres 17' => ['postgres', 1, '17'],
    'postgres 18' => ['postgres', 1, '18'],
    'postgres 17 cluster' => ['postgres', 3, '17'],
    'postgres 18 cluster' => ['postgres', 3, '18'],
    'mariadb 11.4 cluster' => ['mariadb', 3, '11.4'],
    'mariadb 11.8 cluster' => ['mariadb', 3, '11.8'],
    'mysql 8.4' => ['mysql', 1, '8.4'],
    'mysql 9.7' => ['mysql', 1, '9.7'],
    'mysql 8.4 cluster' => ['mysql', 3, '8.4'],
    'mysql 9.7 cluster' => ['mysql', 3, '9.7'],
    'mongodb 7.0' => ['mongodb', 1, '7.0'],
    'mongodb 8.0' => ['mongodb', 1, '8.0'],
    'mongodb 9.0' => ['mongodb', 1, '9.0'],
    'mongodb 7.0 cluster' => ['mongodb', 3, '7.0'],
    'mongodb 8.0 cluster' => ['mongodb', 3, '8.0'],
    'mongodb 9.0 cluster' => ['mongodb', 3, '9.0'],
]);

it('renders every point-in-time recovery script as valid bash', function (string $engine, int $servers, string $line) {
    $database = withRecovery(idleDatabase($engine, $servers, $servers === 1 ? DatabaseMode::Single : DatabaseMode::HighAvailability, versionLine: $line));
    $seed = $database->seed();
    $source = new RecoverySource('orders', $database->pointInTimeRecovery->storage_provider_id, 'mysql/orders-1', CarbonImmutable::parse('2026-10-26 06:30:15'));
    $nodeScripts = collect($database->archiveFiles())
        ->filter(fn (NodeFile $file): bool => str_contains($file->path, '/archive/bin/'))
        ->mapWithKeys(fn (NodeFile $file): array => [$file->path => $file->content]);

    $errors = collect([
        ...$nodeScripts->all(),
        'deploy' => view('ssh.databases.nodes.deploy', $database->provider()->scriptData()->deploy($seed))->render(),
        'status' => view($database->provider()->scriptView('archive.status'), [...$database->provider()->scriptData()->for($seed), ...$database->provider()->scriptData()->archiving(), 'backUpNow' => true])->render(),
        'restore' => view($database->provider()->scriptView('archive.restore'), $database->provider()->scriptData()->restoring($seed, $source))->render(),
        'sync' => view('ssh.databases.recovery.sync', [...$database->provider()->scriptData()->for($seed), ...$database->provider()->scriptData()->archiving(), 'files' => $database->archiveFiles()])->render(),
    ])->map(fn (string $script): string => bashErrorsIn($script))->filter();

    expect($nodeScripts)->not->toBeEmpty()
        ->and($nodeScripts->every(fn (string $script): bool => str_starts_with($script, "#!/bin/bash\nset -euo pipefail\n")))->toBeTrue()
        ->and($errors->all())->toBe([]);
})->with([
    'postgres 17' => ['postgres', 1, '17'],
    'postgres 18 cluster' => ['postgres', 3, '18'],
    'mysql 8.4' => ['mysql', 1, '8.4'],
    'mysql 9.7' => ['mysql', 1, '9.7'],
    'mariadb 11.4 cluster' => ['mariadb', 3, '11.4'],
    'mariadb 11.8 cluster' => ['mariadb', 3, '11.8'],
    'mongodb 7.0' => ['mongodb', 1, '7.0'],
    'mongodb 8.0 cluster' => ['mongodb', 3, '8.0'],
]);

it('renders the host bootstrap and the container shim as valid bash', function (string $engine) {
    $database = idleDatabase($engine, 1, DatabaseMode::Single);
    $server = $database->seed()->server;
    $ssh = fakeSsh();
    $server->ssh()->exec($database->provider()->scriptView('lifecycle.status'), $database->provider()->scriptData()->for());

    $provision = ['aptTimeout' => Ssh::APT_LOCK_TIMEOUT, 'family' => OsFamily::Debian, 'user' => 'root', 'publicKey' => 'ssh-ed25519 AAAA databasify', 'privateIp' => '10.0.0.2', 'dockerVersion' => '29.9.2', 'containerdVersion' => '2.4.2'];

    $errors = collect([
        'shim' => $ssh->scriptAt(0),
        ...collect([
            'ssh.servers.provision.require-private-ip',
            'ssh.servers.users.install-key',
            'ssh.servers.os.update-packages',
            'ssh.servers.os.debian.install-base-tools',
            'ssh.servers.os.debian.install-docker',
            'ssh.servers.os.swap',
            'ssh.servers.users.disable-password-login',
        ])->mapWithKeys(fn (string $view): array => [$view => view($view, $provision)->render()]),
    ])->map(fn (string $script): string => bashErrorsIn($script))->filter();

    expect($ssh->scriptAt(0))->toContain('docker exec -i '.$database->provider()->container())
        ->and($errors->all())->toBe([]);
})->with(['valkey', 'redis', 'mysql', 'postgres', 'mariadb', 'mongodb']);
