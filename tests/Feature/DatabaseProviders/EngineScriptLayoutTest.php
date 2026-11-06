<?php

declare(strict_types=1);

use App\DTOs\Recovery\RecoverySource;
use Carbon\CarbonImmutable;

it('keeps its own file for a step it shares and renders the shared script unchanged', function (string $engine, string $step, string $shared) {
    $database = withRecovery(idleDatabase($engine, 3));
    $seed = $database->seed();
    $source = new RecoverySource('orders', $database->pointInTimeRecovery->storage_provider_id, "{$engine}/orders-1", CarbonImmutable::parse('2026-10-26 06:30:15'));
    $data = [
        ...$database->provider()->scriptData()->starting($seed, bootstrapsCluster: false),
        ...$database->provider()->scriptData()->restoring($seed, $source),
        ...$database->provider()->scriptData()->archiving(),
        'backUpNow' => false,
        'name' => 'orders',
        'password' => 'secret',
        'databases' => ['orders'],
        'users' => ['app'],
        'encryption' => null,
    ];
    $view = $database->provider()->scriptView($step);

    expect($view)->toStartWith("ssh.engines.{$engine}.lines.")->toEndWith(".{$step}")
        ->and(view($view, $data)->render())->not->toBe('')->toBe(view($shared, $data)->render());
})->with([
    ['mysql', 'lifecycle.wake', 'ssh.engines.shared.wake'],
    ['postgres', 'lifecycle.start', 'ssh.engines.shared.start'],
    ['postgres', 'cluster.rejoin', 'ssh.engines.shared.wake'],
    ['mariadb', 'cluster.rejoin', 'ssh.engines.shared.wake'],
    ['mariadb', 'lifecycle.cleanup', 'ssh.engines.mysql.lifecycle.cleanup'],
    ['mariadb', 'config.host-tuning', 'ssh.engines.mysql.config.host-tuning'],
    ['mariadb', 'archive.restore', 'ssh.engines.mysql.archive.restore'],
    ['mariadb', 'archive.status', 'ssh.engines.mysql.archive.status'],
    ['mariadb', 'accounts.create-user', 'ssh.engines.mysql.accounts.create-user'],
    ['mariadb', 'accounts.drop-user', 'ssh.engines.mysql.accounts.drop-user'],
    ['mariadb', 'accounts.reset-password', 'ssh.engines.mysql.accounts.reset-password'],
    ['mariadb', 'accounts.create-database', 'ssh.engines.mysql.accounts.create-database'],
    ['mariadb', 'accounts.drop-database', 'ssh.engines.mysql.accounts.drop-database'],
    ['mongodb', 'lifecycle.wake', 'ssh.engines.shared.wake'],
    ['mongodb', 'cluster.rejoin', 'ssh.engines.shared.wake'],
]);
