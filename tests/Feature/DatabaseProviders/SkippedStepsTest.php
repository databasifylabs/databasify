<?php

declare(strict_types=1);

use App\DatabaseProviders\Contracts\DatabaseDriver;
use App\Exceptions\Databases\MissingEngineScript;
use App\Models\DatabaseNode;

it('runs nothing and writes no log for a step the engine skips', function (string $engine, Closure $step) {
    $database = idleDatabase($engine, 3);
    [$primary, $node] = $database->nodes()->with('server')->get()->all();
    $logs = $database->serverLogs()->count();
    $ssh = fakeSsh();

    $result = $step($database->provider(), $primary, $node);

    expect($ssh->runs)->toBe([])
        ->and($database->serverLogs()->count())->toBe($logs)
        ->and($result)->toBeNull();
})->with([
    'valkey after-bootstrap' => ['valkey', fn (DatabaseDriver $driver, DatabaseNode $primary) => $driver->nodes()->finishBootstrap($primary)],
    'redis after-bootstrap' => ['redis', fn (DatabaseDriver $driver, DatabaseNode $primary) => $driver->nodes()->finishBootstrap($primary)],
    'mysql reset' => ['mysql', fn (DatabaseDriver $driver, DatabaseNode $primary) => $driver->nodes()->reset($primary, 2)],
    'mysql pools' => ['mysql', fn (DatabaseDriver $driver, DatabaseNode $primary) => $driver->nodes()->applyPools($primary)],
    'mysql config sync' => ['mysql', fn (DatabaseDriver $driver, DatabaseNode $primary) => $driver->nodes()->syncConfig($primary)],
    'mariadb wake' => ['mariadb', fn (DatabaseDriver $driver, DatabaseNode $primary) => $driver->nodes()->wake($primary)],
    'mongodb cluster' => ['mongodb', fn (DatabaseDriver $driver, DatabaseNode $primary) => $driver->cluster()->cluster($primary)],
    'mysql admit' => ['mysql', fn (DatabaseDriver $driver, DatabaseNode $primary, DatabaseNode $node) => $driver->cluster()->admit($node)],
    'mongodb admit' => ['mongodb', fn (DatabaseDriver $driver, DatabaseNode $primary, DatabaseNode $node) => $driver->cluster()->admit($node)],
    'valkey admit' => ['valkey', fn (DatabaseDriver $driver, DatabaseNode $primary, DatabaseNode $node) => $driver->cluster()->admit($node)],
    'mariadb adopt' => ['mariadb', fn (DatabaseDriver $driver, DatabaseNode $primary, DatabaseNode $node) => $driver->cluster()->adopt($node)],
    'redis adopt' => ['redis', fn (DatabaseDriver $driver, DatabaseNode $primary, DatabaseNode $node) => $driver->cluster()->adopt($node)],
    'mysql membership' => ['mysql', fn (DatabaseDriver $driver, DatabaseNode $primary, DatabaseNode $node) => $driver->cluster()->updateMembership($node)],
    'mariadb forget' => ['mariadb', fn (DatabaseDriver $driver, DatabaseNode $primary, DatabaseNode $node) => $driver->cluster()->forget($primary, $node)],
    'valkey forget' => ['valkey', fn (DatabaseDriver $driver, DatabaseNode $primary, DatabaseNode $node) => $driver->cluster()->forget($primary, $node)],
    'mysql rejoin' => ['mysql', fn (DatabaseDriver $driver, DatabaseNode $primary, DatabaseNode $node) => $driver->cluster()->rejoin($node)],
    'mysql recovery position' => ['mysql', fn (DatabaseDriver $driver, DatabaseNode $primary, DatabaseNode $node) => $driver->cluster()->recoveryPosition($node)],
    'valkey accounts' => ['valkey', fn (DatabaseDriver $driver) => $driver->accounts()],
]);

it('fails instead of skipping a step whose script is missing', function () {
    $database = idleDatabase('valkey', 1);
    config(['database-provider.providers.valkey.script_overrides' => ['9' => ['lifecycle.status' => 'ssh.engines.valkey.lifecycle.missing']]]);
    $ssh = fakeSsh();

    expect(fn () => $database->provider()->nodes()->status($database->seed()))
        ->toThrow(MissingEngineScript::class, 'The valkey engine has no lifecycle.status script: ssh.engines.valkey.lifecycle.missing does not exist.')
        ->and($ssh->runs)->toBe([]);
});
