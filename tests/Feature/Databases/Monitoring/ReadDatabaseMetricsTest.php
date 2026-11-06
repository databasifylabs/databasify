<?php

declare(strict_types=1);

use App\Actions\Databases\Monitoring\ReadDatabaseMetrics;
use App\Enums\Databases\DatabaseMode;
use App\Models\Database;

it('reads node metrics and marks unreachable nodes', function () {
    $database = idleDatabase('mysql', 2);
    fakeSsh()->respond("cpus=1\nload_1m=0.12\nthreads_connected=4\nstate=PRIMARY ONLINE\n")->respond('', 255);

    $metrics = resolve(ReadDatabaseMetrics::class)->handle($database);

    expect($metrics[0]->values)->toMatchArray(['cpus' => '1', 'threads_connected' => '4', 'state' => 'PRIMARY ONLINE'])
        ->and($metrics[1]->reachable)->toBeFalse();
});

it('collects the engine metrics of each engine', function (string $engine, string $probe) {
    $database = idleDatabase($engine, 1, DatabaseMode::Single);
    $ssh = fakeSsh()->respond("cpus=2\n");

    expect(resolve(ReadDatabaseMetrics::class)->handle($database)->sole()->values)->toBe(['cpus' => '2'])
        ->and($ssh->scriptAt(0))->toContain('dir='.Database::providerFor($engine)->directory())->toContain('/proc/stat')->toContain($probe);
})->with([
    'valkey' => ['valkey', 'instantaneous_ops_per_sec'],
    'redis' => ['redis', 'instantaneous_ops_per_sec'],
    'mysql' => ['mysql', "'UNKNOWN OFFLINE'"],
    'mariadb' => ['mariadb', 'Threads_connected'],
    'postgres' => ['postgres', 'pg_stat_activity'],
    'mongodb' => ['mongodb', 'threads_connected=" + status.connections.current'],
]);
