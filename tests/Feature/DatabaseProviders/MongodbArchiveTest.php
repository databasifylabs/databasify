<?php

declare(strict_types=1);

use App\DTOs\Databases\NodeFile;
use App\DTOs\Recovery\RecoverySource;
use App\Enums\Databases\DatabaseMode;
use App\Models\Database;
use Carbon\CarbonImmutable;

it('runs a pbm agent on every node and a daily backup only while recovery is on', function () {
    $plain = idleDatabase('mongodb', 3);
    $archived = withRecovery(idleDatabase('mongodb', 3, name: 'orders'), ['backup_hour' => 4]);
    $manifest = $archived->provider()->processes()->toArray();

    expect($plain->provider()->processes()->names())->toBe(['mongod'])
        ->and($manifest['processes'][1])->toMatchArray(['name' => 'pbm-agent', 'user' => 'mongodb', 'after' => ['mongod'], 'restart' => 'always'])
        ->and($manifest['processes'][1]['argv'][2])->toStartWith('PBM_MONGODB_URI="mongodb://pbm:$(cat /srv/mongodb/conf/pbm-password)@$(cat /srv/mongodb/conf/own-address)/')->not->toContain('tlsAllowInvalidHostnames')->not->toContain('directConnection')->toEndWith('" exec pbm-agent')
        ->and($manifest['schedules'][0])->toMatchArray(['name' => 'mongodb-backup', 'onCalendar' => '04:00', 'argv' => ['/srv/mongodb/archive/bin/backup'], 'user' => 'root']);
});

it('points pbm at the bucket with oplog slicing for the archive and without it for restores', function () {
    $database = withRecovery(idleDatabase('mongodb', 1, DatabaseMode::Single, 'orders'), ['retention_days' => 9]);
    $files = collect($database->archiveFiles())->keyBy(fn (NodeFile $file): string => basename($file->path));
    $config = json_decode($files['pbm.json']->content, true);
    $restore = json_decode(Database::providerFor('mongodb')->archive()->restoreSource($database->pointInTimeRecovery->location()), true);

    expect($config['storage']['type'])->toBe('s3')
        ->and($config['storage']['s3'])->toHaveKeys(['endpointUrl', 'region', 'bucket', 'forcePathStyle', 'credentials'])
        ->and($config['storage']['s3']['prefix'])->toBe($database->pointInTimeRecovery->path)
        ->and($config['pitr'])->toBe(['enabled' => true, 'oplogSpanMin' => 10])
        ->and($restore['pitr']['enabled'])->toBeFalse()
        ->and($files['pbm.json'])->mode->toBe('600')->owner->toBe('root')
        ->and(strpos($files['backup']->content, 'pbm_agent_ready && break'))->toBeLessThan(strpos($files['backup']->content, 'pbm backup --wait'))
        ->and(bashErrorsIn($files['backup']->content))->toBe('')
        ->and($files['backup']->content)->toContain('pbm backup --wait')->toContain('pbm cleanup --older-than=9d --yes')->toContain('writable_primary');
});

it('keeps the pbm password out of command lines and restores up to the target', function () {
    $database = withRecovery(idleDatabase('mongodb', 1, DatabaseMode::Single, 'orders'));
    $source = new RecoverySource('orders', $database->pointInTimeRecovery->storage_provider_id, 'mongodb/orders-1', CarbonImmutable::parse('2026-10-26 06:30:15'));
    $status = view(Database::providerFor('mongodb')->scriptView('archive.status'), [...$database->provider()->scriptData()->for($database->seed()), ...$database->provider()->scriptData()->archiving(), 'backUpNow' => false])->render();
    $restore = view(Database::providerFor('mongodb')->scriptView('archive.restore'), $database->provider()->scriptData()->restoring($database->seed(), $source))->render();
    preg_match_all("/<<'JS'[^\n]*\n(.*?)\nJS\n/s", $status.$restore, $blocks);

    expect($restore)->toContain("sh -c 'PBM_MONGODB_URI=\"mongodb://pbm:\$(cat /srv/mongodb/conf/pbm-password)@")
        ->toContain("pbm restore --time='2026-10-26T06:30:15' --wait --yes")
        ->and(strpos($restore, 'wait_for 120 pbm_agent_ready'))->toBeGreaterThan(strpos($restore, 'exec pbm-agent'))->toBeLessThan(strpos($restore, "\nresync_storage\n"))
        ->and($restore)->toContain('select(.status == "done" and .restoreTo == '.CarbonImmutable::parse('2026-10-26 06:30:15')->getTimestamp().')')->toContain('pbm restore "$snapshot" --wait --yes')
        ->and(strpos($restore, 'wait_for 300 source_backups'))->toBeLessThan(strpos($restore, 'pbm restore'))
        ->and($restore)
        ->toContain("rs.initiate({ _id: 'databasify'")
        ->and($status)->toContain('pbm status --out json')->toContain('echo "problem=$problem"')
        ->and(strpos($status, 'wait_for 45 pbm_agent_beating'))->toBeLessThan(strpos($status, 'pbm config --file'))
        ->and($status)->not->toContain('pbm_agent_ready >')->toContain('pbmAgents.countDocuments({ hb: { $gte:')->toContain("i: 0 }) } }))' 10)")
        ->and(bashErrorsIn($status))->toBe('')
        ->and($status)->toContain('contains("insufficient range")')->toContain('databasify ctl start --no-block mongodb-backup')
        ->and($blocks[1])->toHaveCount(2)
        ->and(collect($blocks[1])->map(fn (string $block): string => javascriptErrorsIn($block))->filter()->all())->toBe([]);
});

it('starts the pbm agent with its user in place only while recovery is on', function () {
    $plain = idleDatabase('mongodb', 3);
    $archived = withRecovery(idleDatabase('mongodb', 3, name: 'orders'));
    $render = fn ($database): string => view(Database::providerFor('mongodb')->scriptView('lifecycle.start'), $database->provider()->scriptData()->starting($database->seed(), false))->render();

    expect($render($archived))->toContain("admin.createUser({ user: 'pbm'")->toContain('databasify ctl restart pbm-agent')
        ->and(strpos($render($archived), 'mongo_run_on_primary <<'))->toBeLessThan(strpos($render($archived), 'databasify ctl restart pbm-agent'))
        ->and(bashErrorsIn($render($archived)))->toBe('')
        ->and($render($plain))->not->toContain('pbm-agent');
});

it('resyncs the restore storage once pbm is idle and retries only while another operation holds the lock', function (string $running, array $answers, string $expected) {
    $database = withRecovery(idleDatabase('mongodb', 1, DatabaseMode::Single, 'orders'));
    $source = new RecoverySource('orders', $database->pointInTimeRecovery->storage_provider_id, 'mongodb/orders-1', CarbonImmutable::parse('2026-10-26 06:30:15'));
    $restore = view(Database::providerFor('mongodb')->scriptView('archive.restore'), $database->provider()->scriptData()->restoring($database->seed(), $source))->render();
    preg_match('/^pbm_idle\(\).*?\n}\n/ms', $restore, $functions);
    $stub = 'wait_for() { shift; "$@"; }'."\n".'answers=('.implode(' ', array_map(escapeshellarg(...), $answers)).')'."\n"
        .'pbm() { if [ "$1" = status ]; then echo \'{"running":'.$running.'}\'; return 0; fi; echo "resync $*" >> calls; answer=${answers[$(( $(wc -l < calls) - 1 ))]}; [ "$answer" = ok ] && return 0; echo "Error: $answer"; return 1; }'."\n";

    $result = Process::path(sys_get_temp_dir())->input("set -euo pipefail\nrm -f calls\n{$stub}{$functions[0]}resync_storage && echo resynced || echo failed\ncat calls 2> /dev/null || true\nrm -f calls\n")->run(['bash']);

    expect($result->output().$result->errorOutput())->toBe($expected);
})->with([
    'busy, then free' => ['{}', ['another operation in progress, Resync storage/1', 'ok'], "PBM is busy (attempt 1): Error: another operation in progress, Resync storage/1\nresynced\nresync config --force-resync --wait\nresync config --force-resync --wait\n"],
    'bad bucket' => ['{}', ['storage check: access denied'], "failed\nresync config --force-resync --wait\nError: storage check: access denied\n"],
    'busy for good' => ['{"type":"backup"}', [], "failed\nPBM stayed busy with another operation for 5 minutes, so the storage could not be resynced\n"],
]);
