<?php

declare(strict_types=1);

use App\Engines\Data\Storage;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->scripts = fakeScripts();
    $this->node = engineNode();
    $this->node->database->update(['version_line' => '18']);
    $this->node = $this->node->fresh();
});

it('reads the archive status and asks for a backup now', function () {
    $this->scripts->respond('engines.postgres.archive.status', ['backups' => 2, 'earliest' => '2026-11-10T03:00:00Z', 'latest' => '2026-11-12T08:15:00Z', 'lastBackup' => '2026-11-12T03:00:00Z', 'problem' => null]);

    $status = $this->node->driver()->archive()->status(backUpNow: true);

    expect($status)->backups->toBe(2)->problem->toBeNull()
        ->and($status->latest?->toIso8601ZuluString())->toBe('2026-11-12T08:15:00Z');
    $this->scripts->assertRan('engines.postgres.archive.status', fn (string $script, array $env): bool => $env === ['BACK_UP_NOW' => 'true']
        && str_contains($script, 'point-in-time recovery is not configured on this node')
        && str_contains($script, 'ctl start --no-block databasify-postgres-backup'));
});

it('restores to a point in time as a task with the storage keys only in a file', function () {
    $storage = new Storage('https://s3.example.com', 'bk', 'us-east-1', 'AK', "S'ECRET", true, '/db/x');

    $operation = $this->node->driver()->archive()->restore('restore.1', $storage, CarbonImmutable::parse('2026-11-12 08:15:00', 'UTC'));

    expect($operation->key)->toBe('restore.1');
    $this->scripts->assertRan('engines.postgres.restore.start', fn (string $script, array $env): bool => $env['TARGET'] === '2026-11-12 08:15:00'
        && str_contains($env['RESTORE_CONFIG'], "repo1-s3-key-secret=S'ECRET\n")
        && ! str_contains($env['RESTORE_CONFIG'], 'repo1-retention-full')
        && ! str_contains($script, 'ECRET')
        && str_contains($script, "phase 'replaying'") === false && str_contains($script, 'phase replaying'));
});
