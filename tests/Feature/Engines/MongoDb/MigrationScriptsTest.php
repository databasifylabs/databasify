<?php

declare(strict_types=1);

use App\Engines\Scripts\ScriptView;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * @return array{ProcessResult, list<string>, bool}
 */
function runMongoStop(?string $status, bool $cutover, ?string $next = null, bool $unitActive = false, bool $marked = false, bool $dropFails = false, ?string $stubs = null, string $script = 'engines.mongodb.migration.stop'): array
{
    $stubs ??= temporaryDirectory();
    $marker = "{$stubs}/root/state/mongodb-migration.finalized";
    is_dir("{$stubs}/bin") || mkdir("{$stubs}/bin");
    @unlink("{$stubs}/calls");
    @unlink("{$stubs}/status");
    if ($marked) {
        mkdir(dirname($marker), recursive: true);
        touch($marker);
    }
    $status === null || file_put_contents("{$stubs}/status", $status);
    $next === null || file_put_contents("{$stubs}/next", $next);
    $files = [
        'curl' => <<<'BASH'
            #!/bin/bash
            if [[ "$*" == *'-X POST'* ]]; then
                printf '%s\n' "${@: -1}" >> "$STUB_DIR/calls"
                [[ "${@: -1}" == */finalize ]] && printf '{"state":"finalized"}' > "$STUB_DIR/status"
                printf '{"ok":true}'
                exit 0
            fi
            [ -f "$STUB_DIR/status" ] || exit 7
            cat "$STUB_DIR/status"
            if [ -f "$STUB_DIR/next" ]; then mv "$STUB_DIR/next" "$STUB_DIR/status"; fi
            BASH,
        'mongosh' => <<<'BASH'
            #!/bin/bash
            if [[ " $* " == *' --nodb '* ]]; then
                exec node -e "const print = (line) => console.log(line); ${@: -1}"
            fi
            printf 'mongo_js\n' >> "$STUB_DIR/calls"
            [ -z "$STUB_DROP_FAILS" ] || exit 1
            printf 'DATABASIFY_RESULT {}\n'
            BASH,
        'databasify' => <<<'BASH'
            #!/bin/bash
            if [ "$2" = show ]; then
                case $4 in LoadState) echo loaded ;; ActiveState) echo active ;; SubState) echo running ;; *) echo 0 ;; esac
                exit 0
            fi
            printf '%s\n' "$*" >> "$STUB_DIR/calls"
            [ "$2" != is-active ] || [ -n "$STUB_UNIT_ACTIVE" ]
            BASH,
        'timeout' => "#!/bin/bash\nshift\nexec \"\$@\"\n",
        'sleep' => "#!/bin/bash\nexit 0\n",
    ];

    foreach ($files as $name => $content) {
        file_put_contents("{$stubs}/bin/{$name}", $content."\n");
        chmod("{$stubs}/bin/{$name}", 0755);
    }

    $script = str_replace('/srv/mongodb', "{$stubs}/root", resolve(ScriptView::class)->render(idleDatabase('mongodb', 1, name: uniqid('db'))->nodes()->sole(), $script));
    $run = Process::input($script)->env([
        'PATH' => "{$stubs}/bin:".getenv('PATH'),
        'STUB_DIR' => $stubs,
        'STUB_UNIT_ACTIVE' => $unitActive ? '1' : '',
        'STUB_DROP_FAILS' => $dropFails ? '1' : '',
        'MONGO_SECRET' => 'secret',
        'MIGRATION_CUTOVER' => $cutover ? 'true' : 'false',
    ])->run(['bash', '-euo', 'pipefail', '-s']);
    $calls = is_file("{$stubs}/calls") ? array_values(array_filter(explode("\n", (string) file_get_contents("{$stubs}/calls")), fn (string $line): bool => $line !== '')) : [];

    return [$run, $calls, is_file($marker)];
}

const MONGO_STOP_CLEANUP = ['ctl stop mongodb-migration', 'ctl reset-failed mongodb-migration', 'mongo_js'];

it('finalizes or waits for ClusterSync before it cleans up a cutover', function (string $status, ?string $next, array $calls) {
    [$run, $recorded, $marked] = runMongoStop($status, true, $next);

    expect($run->successful())->toBeTrue($run->errorOutput())
        ->and($recorded)->toBe([...$calls, ...MONGO_STOP_CLEANUP])
        ->and($marked)->toBeFalse();
})->with([
    'running' => ['{"state":"running","initialSync":{"completed":true}}', null, ['http://127.0.0.1:2242/finalize']],
    'paused' => ['{"state":"paused","initialSync":{"completed":true}}', null, ['http://127.0.0.1:2242/finalize']],
    'finalizing' => ['{"state":"finalizing","initialSync":{"completed":true}}', '{"state":"finalized"}', []],
    'finalized' => ['{"state":"finalized","initialSync":{"completed":true}}', null, []],
]);

it('refuses a cutover ClusterSync cannot finish and keeps its state', function (?string $status, bool $unitActive, string $error, array $calls) {
    [$run, $recorded, $marked] = runMongoStop($status, true, unitActive: $unitActive);

    expect($run->successful())->toBeFalse()
        ->and($run->errorOutput())->toContain($error)
        ->and($recorded)->toBe($calls)
        ->and($marked)->toBeFalse();
})->with([
    'failed' => ['{"state":"failed","error":"recreate index shop.users.email_1: duplicate key error","initialSync":{"completed":true}}', false, 'ClusterSync cannot finish the cutover (failed: recreate index shop.users.email_1: duplicate key error)', []],
    'pausing' => ['{"state":"pausing","initialSync":{"completed":true}}', false, 'ClusterSync is pausing before it can finalize', []],
    'idle' => ['{"state":"idle"}', false, 'ClusterSync cannot finish the cutover (idle)', []],
    'silent while running' => [null, true, 'ClusterSync is running but does not answer', ['ctl is-active --quiet mongodb-migration']],
    'gone without a confirmed finalize' => [null, false, 'the cutover never confirmed finalized; check indexes before cleaning up', ['ctl is-active --quiet mongodb-migration']],
]);

it('keeps the confirmed finalize when the cleanup fails and cleans up a gone ClusterSync with it later', function () {
    $stubs = temporaryDirectory();
    [$failed, , $markedAfterFailure] = runMongoStop('{"state":"running","initialSync":{"completed":true}}', true, dropFails: true, stubs: $stubs);
    [$retried, $recorded, $markedAfterRetry] = runMongoStop(null, true, stubs: $stubs);

    expect($failed->successful())->toBeFalse()
        ->and($markedAfterFailure)->toBeTrue()
        ->and($retried->successful())->toBeTrue($retried->errorOutput())
        ->and($recorded)->toBe(['ctl is-active --quiet mongodb-migration', ...MONGO_STOP_CLEANUP])
        ->and($markedAfterRetry)->toBeFalse();
});

it('pauses an unsynced ClusterSync or drops a failed one on a plain stop', function (string $status, array $calls) {
    [$run, $recorded] = runMongoStop($status, false);

    expect($run->successful())->toBeTrue($run->errorOutput())
        ->and($recorded)->toBe([...$calls, ...MONGO_STOP_CLEANUP]);
})->with([
    'copying' => ['{"state":"running","initialSync":{"completed":false}}', ['http://127.0.0.1:2242/pause']],
    'failed' => ['{"state":"failed","error":"source gone"}', []],
]);

it('reports what ClusterSync says about a running continuous copy', function (?string $status, array $expected) {
    [$run] = runMongoStop($status, false, script: 'engines.mongodb.migration.progress');

    expect($run->successful())->toBeTrue($run->errorOutput())
        ->and(json_decode(trim(str_replace('DATABASIFY_RESULT ', '', $run->output())), true))->toBe($expected);
})->with([
    'initial sync done' => ['{"state":"running","lagTimeSeconds":3,"initialSync":{"completed":true}}', ['copy' => 'succeeded', 'log' => '', 'lagSeconds' => 3]],
    'still cloning' => ['{"state":"running","lagTimeSeconds":0,"initialSync":{"completed":false}}', ['copy' => 'running', 'log' => '', 'lagSeconds' => null]],
    'failed' => ['{"ok":false,"error":"clone: startTS: advance source cluster time: (Unauthorized) not authorized on admin","state":"failed","initialSync":{"completed":false,"cloneCompleted":true}}', ['copy' => 'failed', 'log' => 'ClusterSync failed: clone: startTS: advance source cluster time: (Unauthorized) not authorized on admin', 'lagSeconds' => null]],
    'not answering yet' => [null, ['copy' => 'running', 'log' => '', 'lagSeconds' => null]],
]);
