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

/**
 * @param  array<string, mixed>  $replies
 * @param  array<string, string>  $env
 * @return array{string, list<array<string, mixed>>, ProcessResult}
 */
function runMongoScriptAgainst(string $script, array $replies, array $env = [], bool $succeeds = true): array
{
    $stubs = temporaryDirectory();
    mkdir("{$stubs}/bin");
    file_put_contents("{$stubs}/bin/timeout", "#!/bin/bash\nshift\nexec \"\$@\"\n");
    file_put_contents("{$stubs}/bin/mongosh", <<<'BASH'
        #!/bin/bash
        if [[ " $* " == *' --nodb '* && " $* " != *' --file '* ]]; then
            exec node -e "const print = (line) => console.log(line); ${@: -1}"
        fi
        program=$(mktemp)
        cat > "$program" <<'JS'
        const fs = require('fs');
        const print = (line) => console.log(line);
        const quit = (code) => process.exit(code);
        const EJSON = { stringify: JSON.stringify };
        const replies = JSON.parse(process.env.STUB_REPLIES);
        const sleep = () => {};
        const answer = (name) => Array.isArray(replies[name]) ? (replies[name].length > 1 ? replies[name].shift() : replies[name][0]) : replies[name];
        const db = { getSiblingDB: () => ({ auth: () => {}, runCommand: (command) => { fs.appendFileSync(process.env.STUB_DIR + '/commands', JSON.stringify(command) + '\n'); return answer(Object.keys(command)[0]) || { ok: 1 }; } }) };
        class Mongo { getDB() { return db.getSiblingDB('admin'); } close() {} }
        JS
        cat "${@: -1}" >> "$program"
        node "$program"
        BASH);
    chmod("{$stubs}/bin/timeout", 0755);
    chmod("{$stubs}/bin/mongosh", 0755);

    $rendered = resolve(ScriptView::class)->render(idleDatabase('mongodb', 1, name: uniqid('db'))->nodes()->sole(), $script);
    $run = Process::input($rendered)->env([...$env, 'PATH' => "{$stubs}/bin:".getenv('PATH'), 'STUB_DIR' => $stubs, 'STUB_REPLIES' => json_encode((object) $replies, JSON_THROW_ON_ERROR), 'MONGO_SECRET' => 'secret'])->run(['bash', '-euo', 'pipefail', '-s']);

    if ($succeeds) {
        expect($run->successful())->toBeTrue($run->errorOutput());
    }
    $commands = is_file("{$stubs}/commands") ? array_values(array_map(fn (string $line): array => (array) json_decode($line, true), array_filter(explode("\n", (string) file_get_contents("{$stubs}/commands"))))) : [];

    return [$run->output(), $commands, $run];
}

/**
 * @param  list<array{role: string, db: string}>  $roles
 * @return array<string, mixed>
 */
function checkMongoSourceWithRoles(array $roles): array
{
    [$output] = runMongoScriptAgainst('engines.mongodb.migration.check', [
        'buildInfo' => ['version' => '8.0.12'],
        'listDatabases' => ['databases' => [['name' => 'app', 'sizeOnDisk' => 1024]]],
        'hello' => ['setName' => 'rs0'],
        'connectionStatus' => ['authInfo' => ['authenticatedUserRoles' => $roles]],
        'listCollections' => ['cursor' => ['firstBatch' => []]],
    ], ['SOURCE_HOST' => 'src:27017', 'SOURCE_USER' => 'm', 'SOURCE_PASSWORD' => 'pw', 'SOURCE_TLS' => 'true', 'DATABASES' => '[]', 'RESERVED_DATABASES' => '["admin","local","config"]']);

    return (array) json_decode(trim((string) preg_replace('/^.*DATABASIFY_RESULT /s', '', $output)), true);
}

it('lists the clustersync roles the source user lacks', function (array $roles, array $missing) {
    expect(checkMongoSourceWithRoles($roles))->toMatchArray(['error' => null, 'setName' => 'rs0', 'missingRoles' => $missing]);
})->with([
    'all three' => [[['role' => 'backup', 'db' => 'admin'], ['role' => 'clusterMonitor', 'db' => 'admin'], ['role' => 'readAnyDatabase', 'db' => 'admin']], []],
    'root' => [[['role' => 'root', 'db' => 'admin']], []],
    'read on local only' => [[['role' => 'read', 'db' => 'local'], ['role' => 'readWrite', 'db' => 'app']], ['backup', 'clusterMonitor', 'readAnyDatabase']],
    'backup on another database' => [[['role' => 'backup', 'db' => 'app'], ['role' => 'clusterMonitor', 'db' => 'admin']], ['backup', 'readAnyDatabase']],
]);

it('adds the clustersync source roles to a user without replacing its other roles', function () {
    [, $commands] = runMongoScriptAgainst('engines.mongodb.accounts.grant-source-roles', [], ['NAME' => 'databasify']);

    expect($commands)->toBe([['grantRolesToUser' => 'databasify', 'roles' => [['role' => 'backup', 'db' => 'admin'], ['role' => 'clusterMonitor', 'db' => 'admin'], ['role' => 'readAnyDatabase', 'db' => 'admin']]]]);
});

it('gives a user written with replication its databases and the clustersync source roles', function () {
    [, $commands] = runMongoScriptAgainst('engines.mongodb.accounts.grant', ['listDatabases' => ['databases' => [['name' => 'defaultdb']]]], ['NAME' => 'databasify', 'GRANTS' => '[{"database":"defaultdb","access":"write"}]', 'REPLICATION' => 'true']);

    expect(end($commands))->toBe(['updateUser' => 'databasify', 'roles' => [['role' => 'readWrite', 'db' => 'defaultdb'], ['role' => 'backup', 'db' => 'admin'], ['role' => 'clusterMonitor', 'db' => 'admin'], ['role' => 'readAnyDatabase', 'db' => 'admin']]]);
});

/**
 * @param  list<array<string, mixed>>  $reconfigReplies
 * @return array{list<array<string, mixed>>, ProcessResult}
 */
function leaveReplicaSetWith(array $reconfigReplies, bool $succeeds = true, string $self = '10.0.0.1:25060'): array
{
    $config = ['_id' => 'databasify', 'version' => 3, 'term' => 2, 'members' => [['_id' => 0, 'host' => '10.0.0.1:25060'], ['_id' => 1, 'host' => '10.0.0.2:25060'], ['_id' => 2, 'host' => '10.0.0.3:25060', 'votes' => 0, 'priority' => 0]]];
    [, $commands, $run] = runMongoScriptAgainst('engines.mongodb.cluster.disable', [
        'replSetGetConfig' => [['ok' => 1, 'config' => $config, 'commitmentStatus' => false], ['ok' => 1, 'config' => $config, 'commitmentStatus' => true]],
        'hello' => ['ok' => 1, 'isWritablePrimary' => true],
        'replSetReconfig' => $reconfigReplies,
    ], ['SELF' => $self, 'HOSTS' => "10.0.0.1:25060,{$self}"], $succeeds);

    return [array_values(array_filter($commands, fn (array $command): bool => array_key_exists('replSetReconfig', $command))), $run];
}

it('waits for the previous replica set config to commit before it reconfigures', function () {
    [$reconfigs, $run] = leaveReplicaSetWith([
        ['ok' => 0, 'code' => 117, 'errmsg' => 'Cannot run replSetReconfig because the node is currently updating its configuration'],
        ['ok' => 1],
    ]);

    expect($run->output())->toContain('Left the replica set.')
        ->and($reconfigs)->toHaveCount(2)
        ->and(array_column($reconfigs, 'force'))->toBe([false, false])
        ->and(array_column($reconfigs[1]['replSetReconfig']['members'], 'host'))->toBe(['10.0.0.1:25060']);
});

it('stops at once on a reconfig error other than a config still being updated', function () {
    [$reconfigs, $run] = leaveReplicaSetWith([['ok' => 0, 'code' => 103, 'errmsg' => 'version field value of 4 is not greater than the current version']], succeeds: false);

    expect($run->successful())->toBeFalse()
        ->and($run->output().$run->errorOutput())->toContain('is not greater than the current version')
        ->and($reconfigs)->toHaveCount(1);
});

it('waits for a read-only node to finish leaving before it makes itself a set of one', function () {
    [$reconfigs, $run] = leaveReplicaSetWith([
        ['ok' => 1],
        ['ok' => 0, 'code' => 117, 'errmsg' => 'Cannot run replSetReconfig because the node is currently updating its configuration'],
        ['ok' => 1],
    ], self: '10.0.0.3:25060');

    expect($run->output())->toContain('Left the replica set.')
        ->and(array_column($reconfigs, 'force'))->toBe([false, true, true])
        ->and(array_column($reconfigs[0]['replSetReconfig']['members'], 'host'))->toBe(['10.0.0.1:25060', '10.0.0.2:25060'])
        ->and(array_column($reconfigs[2]['replSetReconfig']['members'], 'host'))->toBe(['10.0.0.3:25060']);
});
