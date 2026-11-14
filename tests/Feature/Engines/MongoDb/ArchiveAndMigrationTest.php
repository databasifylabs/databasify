<?php

declare(strict_types=1);

use App\Engines\Data\MigrationSource;
use App\Engines\Data\MigrationStart as MigrationStartData;
use App\Engines\Data\Storage;
use App\Exceptions\Engines\EngineBusy;
use App\Exceptions\Engines\EngineUnsupported;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->scripts = fakeScripts();
    $this->node = idleDatabase('mongodb', 1, versionLine: '8.0')->nodes()->sole();
});

function savedMongo(string $case): void
{
    respondToFileGet(fakeSsh(), 'state/node.json', json_encode(goFixture("configure-{$case}.json", 'mongodb')['request']));
}

it('reports the archive and starts a backup when there is none', function () {
    savedMongo('archived');
    $this->scripts->respond('mongodb/archive/status', ['snapshots' => [], 'chunks' => [], 'problem' => null, 'backupResult' => 'success']);

    $status = $this->node->driver()->archive()->status(false);

    expect($status->backups)->toBe(0);
    $this->scripts->assertRan('mongodb/archive/status', fn (string $script, array $env): bool => $env['PBM_CONFIG'] === goFixture('pbm-config.json', 'mongodb')['sliced']
        && $env['PBM_MONGODB_URI'] === goFixture('pbm-config.json', 'mongodb')['uri']
        && str_contains($script, 'pbm_run status --out=json'))
        ->assertRan('mongodb/archive/back-up');
});

it('reports a failed backup unit and does not start another', function () {
    savedMongo('archived');
    $this->scripts->respond('mongodb/archive/status', ['snapshots' => [1794283200, 1794369600], 'chunks' => [['start' => 1794283201, 'end' => 1794470400]], 'problem' => null, 'backupResult' => 'exit-code']);

    $status = $this->node->driver()->archive()->status(false);

    expect($status->problem)->toBe('The last backup failed, see the mongodb-backup log.')->and($status->latest)->toEqual(CarbonImmutable::parse('2026-11-12T08:00:00Z'));
    $this->scripts->assertNotRan('mongodb/archive/back-up');
});

it('offers only the times PBM can restore, from a base backup through its unbroken oplog', function (array $snapshots, array $chunks, ?string $earliest, ?string $latest) {
    savedMongo('archived');
    $this->scripts->respond('mongodb/archive/status', ['snapshots' => $snapshots, 'chunks' => $chunks, 'problem' => null, 'backupResult' => 'success']);

    $status = $this->node->driver()->archive()->status(false);

    expect($status->backups)->toBe(count($snapshots))
        ->and($status->earliest)->toEqual($earliest === null ? null : CarbonImmutable::parse($earliest))
        ->and($status->latest)->toEqual($latest === null ? null : CarbonImmutable::parse($latest))
        ->and($status->lastBackup)->toEqual($snapshots === [] ? null : CarbonImmutable::createFromTimestampUTC(max($snapshots)));
})->with([
    'no backup yet' => [[], [], null, null],
    'backups without oplog yet' => [[1794526554, 1794526571], [], '2026-11-12T23:36:11Z', '2026-11-12T23:36:11Z'],
    'oplog starting after the second of two back-to-back backups' => [[1794526554, 1794526571], [['start' => 1794526572, 'end' => 1794527199]], '2026-11-12T23:36:11Z', '2026-11-12T23:46:39Z'],
    'daily backups along one unbroken oplog' => [[1794283200, 1794369600], [['start' => 1794283201, 'end' => 1794400000], ['start' => 1794400000, 'end' => 1794470400]], '2026-11-10T04:00:00Z', '2026-11-12T08:00:00Z'],
    'oplog with no base backup before it' => [[1794283200], [['start' => 1794300000, 'end' => 1794470400]], '2026-11-10T04:00:00Z', '2026-11-10T04:00:00Z'],
]);

it('needs an archive for status and restore, with the Go wording', function () {
    savedMongo('standalone');
    $storage = new Storage('https://s3.example.com', 'bk', 'us-east-1', 'AK', 'SK', true, '/x');

    expect(fn () => $this->node->driver()->archive()->status(false))->toThrow(EngineUnsupported::class, 'this engine does not implement "archive status"')
        ->and(fn () => $this->node->driver()->archive()->restore('r.1', $storage, CarbonImmutable::now()))->toThrow(EngineBusy::class, 'configure archive on this database before restoring into it');
});

it('restores with PBM as a task', function () {
    savedMongo('archived');

    $this->node->driver()->archive()->restore('r.1', new Storage('https://s3.example.com', 'bk', 'us-east-1', 'AK', 'SK', true, '/other'), CarbonImmutable::parse('2026-11-12 10:00:00', 'Asia/Dhaka'));

    $this->scripts->assertRan('mongodb/restore/start', fn (string $script, array $env): bool => $env['TARGET'] === '2026-11-12T04:00:00'
        && str_contains($env['RESTORE_CONFIG'], '"prefix": "/other"') && str_contains($env['RESTORE_CONFIG'], '"enabled": false')
        && str_contains($script, "phase 'stopping oplog slicing'") && str_contains($script, 'PBM could not read the backup storage: ')
        && str_contains($script, 'pitr.enabled=true'));
});

it('checks a source with the Go readiness rules', function (string $version, string $setName, bool $canReplicate, array $warnings) {
    $this->scripts->respond('mongodb/migration/check', ['error' => null, 'version' => $version, 'setName' => $setName, 'databases' => ['app'], 'unreadable' => ['gone'], 'occupied' => ['app'], 'size' => 3145728]);

    $check = $this->node->driver()->migration()->check(new MigrationSource('10.0.0.9', 27017, 'm', "p'w", true), ['app', 'gone']);

    expect($check->supportsContinuous())->toBe($canReplicate)->and($check->warnings)->toBe($warnings)->and($check->facts['size_mb'])->toBe('3')->and($check->problems)->toBe(['Databasify cannot read gone on the source.']);
    $this->scripts->assertRan('mongodb/migration/check', fn (string $script, array $env): bool => $env['SOURCE_HOST'] === '10.0.0.9:27017' && $env['SOURCE_PASSWORD'] === "p'w" && ! str_contains($script, "p'w"));
})->with([
    'same major replica set' => ['8.0.12', 'rs0', true, []],
    'standalone older' => ['7.0.3', '', false, ['the source is a standalone server without an oplog; only a one-shot copy is possible', 'continuous sync needs the same major version (8.0); only a one-shot copy is possible']],
]);

it('starts, reads and stops the migration', function () {
    $this->scripts->respond('mongodb/migration/progress', ['copy' => 'running', 'log' => 'x', 'lagSeconds' => null]);

    $this->node->driver()->migration()->start(new MigrationStartData(new MigrationSource('10.0.0.9', 27017, 'm', 'pw', false), ['app'], true, null));
    $progress = $this->node->driver()->migration()->progress();
    $this->node->driver()->migration()->stop();

    expect($progress->lagSeconds)->toBeNull();
    $this->scripts->assertRan('mongodb/migration/start', fn (string $script, array $env): bool => $env['MIGRATION_CONTINUOUS'] === 'true' && str_contains((string) base64_decode($env['COPY_SCRIPT']), 'pcsm --log-no-color'))
        ->assertRan('mongodb/migration/stop', fn (string $script): bool => str_contains($script, 'pcsm_post /finalize') && str_contains($script, 'percona_clustersync_mongodb'));
});
