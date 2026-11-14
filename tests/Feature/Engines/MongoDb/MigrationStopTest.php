<?php

declare(strict_types=1);

use App\Engines\Scripts\ScriptView;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * @return array{ProcessResult, list<string>}
 */
function runMongoStop(?string $status, bool $cutover, ?string $next = null, bool $unitActive = false): array
{
    $stubs = temporaryDirectory();
    mkdir("{$stubs}/bin");
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
            printf 'DATABASIFY_RESULT {}\n'
            BASH,
        'databasify' => <<<'BASH'
            #!/bin/bash
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

    $script = resolve(ScriptView::class)->render(idleDatabase('mongodb', 1)->nodes()->sole(), 'engines.mongodb.migration.stop');
    $run = Process::input($script)->env([
        'PATH' => "{$stubs}/bin:".getenv('PATH'),
        'STUB_DIR' => $stubs,
        'STUB_UNIT_ACTIVE' => $unitActive ? '1' : '',
        'MONGO_SECRET' => 'secret',
        'MIGRATION_CUTOVER' => $cutover ? 'true' : 'false',
    ])->run(['bash', '-euo', 'pipefail', '-s']);
    $calls = is_file("{$stubs}/calls") ? array_values(array_filter(explode("\n", (string) file_get_contents("{$stubs}/calls")), fn (string $line): bool => $line !== '')) : [];

    return [$run, $calls];
}

const MONGO_STOP_CLEANUP = ['ctl stop mongodb-migration', 'ctl reset-failed mongodb-migration', 'mongo_js'];

it('finalizes or waits for ClusterSync before it cleans up a cutover', function (string $status, ?string $next, array $calls) {
    [$run, $recorded] = runMongoStop($status, true, $next);

    expect($run->successful())->toBeTrue($run->errorOutput())
        ->and($recorded)->toBe([...$calls, ...MONGO_STOP_CLEANUP]);
})->with([
    'running' => ['{"state":"running","initialSync":{"completed":true}}', null, ['http://127.0.0.1:2242/finalize']],
    'paused' => ['{"state":"paused","initialSync":{"completed":true}}', null, ['http://127.0.0.1:2242/finalize']],
    'finalizing' => ['{"state":"finalizing","initialSync":{"completed":true}}', '{"state":"finalized"}', []],
    'finalized' => ['{"state":"finalized","initialSync":{"completed":true}}', null, []],
]);

it('refuses a cutover ClusterSync cannot finish and keeps its state', function (?string $status, bool $unitActive, string $error, array $calls) {
    [$run, $recorded] = runMongoStop($status, true, unitActive: $unitActive);

    expect($run->successful())->toBeFalse()
        ->and($run->errorOutput())->toContain($error)
        ->and($recorded)->toBe($calls);
})->with([
    'failed' => ['{"state":"failed","error":"recreate index shop.users.email_1: duplicate key error","initialSync":{"completed":true}}', false, 'ClusterSync cannot finish the cutover (failed: recreate index shop.users.email_1: duplicate key error)', []],
    'pausing' => ['{"state":"pausing","initialSync":{"completed":true}}', false, 'ClusterSync is pausing before it can finalize', []],
    'idle' => ['{"state":"idle"}', false, 'ClusterSync cannot finish the cutover (idle)', []],
    'silent while running' => [null, true, 'ClusterSync is running but does not answer', ['ctl is-active --quiet mongodb-migration']],
]);

it('cleans up a cutover whose ClusterSync is already gone', function () {
    [$run, $recorded] = runMongoStop(null, true);

    expect($run->successful())->toBeTrue($run->errorOutput())
        ->and($recorded)->toBe(['ctl is-active --quiet mongodb-migration', ...MONGO_STOP_CLEANUP]);
});

it('pauses an unsynced ClusterSync or drops a failed one on a plain stop', function (string $status, array $calls) {
    [$run, $recorded] = runMongoStop($status, false);

    expect($run->successful())->toBeTrue($run->errorOutput())
        ->and($recorded)->toBe([...$calls, ...MONGO_STOP_CLEANUP]);
})->with([
    'copying' => ['{"state":"running","initialSync":{"completed":false}}', ['http://127.0.0.1:2242/pause']],
    'failed' => ['{"state":"failed","error":"source gone"}', []],
]);
