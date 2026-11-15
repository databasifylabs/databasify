<?php

declare(strict_types=1);

use App\Engines\Scripts\ScriptView;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * @return array{ProcessResult, list<string>, bool}
 */
function runMongoStop(?string $status, bool $cutover, ?string $next = null, bool $unitActive = false, bool $marked = false, bool $dropFails = false, ?string $stubs = null, string $script = 'engines.mongodb.migration.stop', ?string $mongosh = null): array
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

    $files['mongosh'] = $mongosh ?? $files['mongosh'];

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

dataset('clustersync stop states', function (): iterable {
    $outcomes = [
        'running' => ['finalize', 'finalize', 'finalize', 'pause'],
        'paused' => ['finalize', 'finalize', 'finalize', 'none'],
        'pausing' => ['busy', 'busy', 'busy', 'none'],
        'finalizing' => ['wait', 'wait', 'wait', 'wait'],
        'finalized' => ['done', 'done', 'done', 'done'],
        'failed' => ['refuse', 'refuse', 'none', 'none'],
        'idle' => ['refuse', 'refuse', 'none', 'none'],
        '' => ['refuse', 'refuse', 'none', 'none'],
    ];

    foreach ($outcomes as $state => $steps) {
        foreach ([[true, true], [true, false], [false, true], [false, false]] as $index => [$cutover, $synced]) {
            $label = sprintf('%s, %s, %s', $state === '' ? 'no state' : $state, $cutover ? 'cutover' : 'plain stop', $synced ? 'synced' : 'unsynced');

            yield $label => [$state, $cutover, $synced, $steps[$index]];
        }
    }
});

it('takes the same ClusterSync step for every state, cutover and sync', function (string $state, bool $cutover, bool $synced, string $step) {
    $status = json_encode(array_filter(['state' => $state, 'error' => 'boom', 'initialSync' => ['completed' => $synced]], fn (mixed $value): bool => $value !== ''), JSON_THROW_ON_ERROR);
    [$run, $recorded, $marked] = runMongoStop($status, $cutover, $step === 'wait' ? '{"state":"finalized"}' : null);

    $failure = match ($step) {
        'busy' => 'ClusterSync is pausing before it can finalize, clean up again once it paused',
        'refuse' => sprintf('ClusterSync cannot finish the cutover (%s: boom); the target keeps its ClusterSync state, fix ClusterSync and clean up again', $state),
        default => null,
    };
    $calls = match ($step) {
        'finalize' => ['http://127.0.0.1:2242/finalize', ...MONGO_STOP_CLEANUP],
        'pause' => ['http://127.0.0.1:2242/pause', ...MONGO_STOP_CLEANUP],
        'busy', 'refuse' => [],
        default => MONGO_STOP_CLEANUP,
    };

    expect($run->successful())->toBe($failure === null, $run->errorOutput())
        ->and($recorded)->toBe($calls)
        ->and($marked)->toBeFalse();

    if ($failure !== null) {
        expect($run->errorOutput())->toContain($failure);
    }
})->with('clustersync stop states');

it('refuses to stop on a ClusterSync status it cannot read or a finalize that fails', function (string $status, ?string $next, string $error, array $calls) {
    [$run, $recorded, $marked] = runMongoStop($status, true, $next);

    expect($run->successful())->toBeFalse()
        ->and($run->errorOutput())->toContain($error)
        ->and($recorded)->toBe($calls)
        ->and($marked)->toBeFalse();
})->with([
    'not JSON' => ['<html>', null, 'could not read the ClusterSync status: <html>', []],
    'finalize fails' => ['{"state":"finalizing"}', '{"state":"failed","error":"index build failed"}', 'ClusterSync could not finalize: index build failed', []],
]);

it('refuses to clean up when the ClusterSync step comes back empty', function () {
    [$run, $recorded, $marked] = runMongoStop('{"state":"running","initialSync":{"completed":true}}', true, mongosh: "#!/bin/bash\n[[ \" \$* \" == *' --nodb '* ]] && exit 0\nprintf 'mongo_js\\n' >> \"\$STUB_DIR/calls\"\n");

    expect($run->successful())->toBeFalse()
        ->and($run->errorOutput())->toContain("ClusterSync gave an unknown stop step '', so nothing was cleaned up")
        ->and($recorded)->toBe([])
        ->and($marked)->toBeFalse();
});

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
    file_put_contents("{$stubs}/bin/databasify", "#!/bin/bash\nexit 0\n");
    chmod("{$stubs}/bin/databasify", 0755);
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
        let elapsed = 0;
        const realNow = Date.now.bind(Date);
        Date.now = () => realNow() + elapsed;
        const sleep = (milliseconds) => { elapsed += milliseconds; };
        const answer = (name, host) => {
          const source = host && replies[host] && replies[host][name] !== undefined ? replies[host] : replies;
          const value = source[name];
          return Array.isArray(value) ? (value.length > 1 ? value.shift() : value[0]) : value;
        };
        const databaseOn = (host) => ({ getSiblingDB: () => ({ auth: () => {}, runCommand: (command) => { fs.appendFileSync(process.env.STUB_DIR + '/commands', JSON.stringify(host ? Object.assign({ on: host }, command) : command) + '\n'); return answer(Object.keys(command)[0], host) || { ok: 1 }; } }) });
        const db = databaseOn(null);
        class Mongo {
          constructor(uri) { this.host = String(uri).replace(/^mongodb:\/\//, '').split('/')[0]; fs.appendFileSync(process.env.STUB_DIR + '/commands', JSON.stringify({ connect: this.host }) + '\n'); }
          getDB() { return databaseOn(this.host).getSiblingDB('admin'); }
          close() { fs.appendFileSync(process.env.STUB_DIR + '/commands', JSON.stringify({ close: this.host }) + '\n'); }
        }
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
function leaveReplicaSetWith(array $reconfigReplies, bool $succeeds = true, string $self = '10.0.0.2:25060', ?array $members = null): array
{
    $config = ['_id' => 'databasify', 'version' => 3, 'term' => 2, 'members' => $members ?? [['_id' => 0, 'host' => '10.0.0.1:25060'], ['_id' => 1, 'host' => '10.0.0.2:25060'], ['_id' => 2, 'host' => '10.0.0.3:25060', 'votes' => 0, 'priority' => 0]]];
    [, $commands, $run] = runMongoScriptAgainst('engines.mongodb.cluster.disable', [
        'replSetGetConfig' => [['ok' => 1, 'config' => $config, 'commitmentStatus' => false], ['ok' => 1, 'config' => $config, 'commitmentStatus' => true]],
        'hello' => ['ok' => 1, 'isWritablePrimary' => true],
        'replSetReconfig' => $reconfigReplies,
    ], ['SELF' => $self, 'HOSTS' => "10.0.0.1:25060,{$self}"], $succeeds);

    return [array_values(array_filter($commands, fn (array $command): bool => array_key_exists('replSetReconfig', $command))), $run];
}

it('waits for the previous replica set config to commit before it reconfigures', function () {
    [$reconfigs, $run] = leaveReplicaSetWith([
        ['ok' => 0, 'errmsg' => 'Cannot run replSetReconfig because the node is currently updating its configuration', 'code' => 109, 'codeName' => 'ConfigurationInProgress'],
        ['ok' => 1],
    ]);

    expect($run->output())->toContain('Left the replica set.')
        ->and($reconfigs)->toHaveCount(2)
        ->and(array_column($reconfigs, 'force'))->toBe([false, false])
        ->and(array_column($reconfigs[1]['replSetReconfig']['members'], 'host'))->toBe(['10.0.0.1:25060', '10.0.0.3:25060']);
});

it('refuses to leave while it is still the primary of a set with other members', function () {
    [$reconfigs, $run] = leaveReplicaSetWith([['ok' => 1]], succeeds: false, self: '10.0.0.1:25060');

    expect($run->successful())->toBeFalse()
        ->and($run->output().$run->errorOutput())->toContain('fail over to another member first')
        ->and($reconfigs)->toBe([]);
});

it('leaves without a reconfig when it is already the only member', function () {
    [$reconfigs, $run] = leaveReplicaSetWith([['ok' => 1]], self: '10.0.0.1:25060', members: [['_id' => 0, 'host' => '10.0.0.1:25060']]);

    expect($run->output())->toContain('Left the replica set.')
        ->and($reconfigs)->toBe([]);
});

it('stops at once on a reconfig error other than a config still being updated', function () {
    [$reconfigs, $run] = leaveReplicaSetWith([['ok' => 0, 'code' => 103, 'errmsg' => 'version field value of 4 is not greater than the current version']], succeeds: false);

    expect($run->successful())->toBeFalse()
        ->and($run->output().$run->errorOutput())->toContain('is not greater than the current version')
        ->and($reconfigs)->toHaveCount(1);
});

it('lets the primary remove a leaving read-only node and never force-reconfigures the node, so its config cannot reach the members that remain', function () {
    [$reconfigs, $run] = leaveReplicaSetWith([
        ['ok' => 0, 'errmsg' => 'Cannot run replSetReconfig because the node is currently updating its configuration', 'code' => 109, 'codeName' => 'ConfigurationInProgress'],
        ['ok' => 1],
    ], self: '10.0.0.3:25060');

    expect($run->output())->toContain('Left the replica set.')
        ->and(array_column($reconfigs, 'force'))->toBe([false, false])
        ->and(array_column($reconfigs[1]['replSetReconfig']['members'], 'host'))->toBe(['10.0.0.1:25060', '10.0.0.2:25060']);
});

it('waits through an election for a primary before it forgets a member', function () {
    $config = ['_id' => 'databasify', 'version' => 5, 'term' => 3, 'members' => [['_id' => 0, 'host' => '10.0.0.1:25060'], ['_id' => 1, 'host' => '10.0.0.2:25060'], ['_id' => 2, 'host' => '10.0.0.4:25060', 'votes' => 0, 'priority' => 0]]];
    [$output, $commands] = runMongoScriptAgainst('engines.mongodb.members.forget', [
        'hello' => [['ok' => 1, 'isWritablePrimary' => false], ['ok' => 1, 'isWritablePrimary' => false], ['ok' => 1, 'isWritablePrimary' => true]],
        'replSetGetConfig' => ['ok' => 1, 'config' => $config, 'commitmentStatus' => true],
        'replSetReconfig' => ['ok' => 1],
    ], ['GONE' => '10.0.0.4:25060', 'HOSTS' => '10.0.0.1:25060,10.0.0.2:25060']);
    $reconfigs = array_values(array_filter($commands, fn (array $command): bool => array_key_exists('replSetReconfig', $command)));

    expect($output)->toContain('Removed 10.0.0.4:25060 from the replica set.')
        ->and(array_filter($commands, fn (array $command): bool => array_key_exists('hello', $command)))->toHaveCount(3)
        ->and($reconfigs)->toHaveCount(1)
        ->and($reconfigs[0]['force'])->toBeFalse()
        ->and(array_column($reconfigs[0]['replSetReconfig']['members'], 'host'))->toBe(['10.0.0.1:25060', '10.0.0.2:25060']);
});

/**
 * @param  array<string, mixed>  $own
 * @param  list<array<string, mixed>>  $serving
 * @return array{list<array<string, mixed>>, ProcessResult}
 */
function rejoinWith(array $own, array $serving, bool $succeeds = true, ?array $set = null, ?array $localHellos = null, ?array &$all = null): array
{
    $set ??= SET_CONFIG;
    [, $commands, $run] = runMongoScriptAgainst('engines.mongodb.cluster.rejoin', [
        '127.0.0.1:25060' => [
            'hello' => $localHellos ?? [['ok' => 1, 'secondary' => false, 'isWritablePrimary' => false], ['ok' => 1, 'secondary' => true]],
            'replSetGetConfig' => ['ok' => 1, 'config' => $own],
            'replSetGetStatus' => ['ok' => 1, 'members' => [['name' => '10.0.0.3:25060', 'self' => true, 'stateStr' => 'REMOVED']]],
        ],
        '10.0.0.1:25060' => [
            'hello' => ['ok' => 1, 'isWritablePrimary' => true],
            'replSetGetConfig' => array_is_list($set) ? array_map(fn (array $config): array => ['ok' => 1, 'config' => $config, 'commitmentStatus' => true], $set) : ['ok' => 1, 'config' => $set, 'commitmentStatus' => true],
            'replSetGetStatus' => ['ok' => 1, 'members' => $serving],
            'replSetReconfig' => ['ok' => 1],
        ],
    ], ['SELF' => '10.0.0.3:25060', 'HOSTS' => '10.0.0.1:25060,10.0.0.2:25060,10.0.0.3:25060', 'READ_ONLY' => 'true'], $succeeds);

    $all = $commands;

    return [array_values(array_filter($commands, fn (array $command): bool => array_key_exists('replSetReconfig', $command))), $run];
}

const SET_CONFIG = ['_id' => 'databasify', 'version' => 12, 'term' => 3, 'members' => [['_id' => 0, 'host' => '10.0.0.1:25060'], ['_id' => 1, 'host' => '10.0.0.2:25060'], ['_id' => 2, 'host' => '10.0.0.3:25060']]];

const STRANDED_CONFIG = ['_id' => 'databasify', 'version' => 30207, 'members' => [['_id' => 3, 'host' => '10.0.0.9:25060', 'votes' => 1, 'priority' => 1]]];

const NOT_SERVING = ['ok' => 1, 'secondary' => false, 'isWritablePrimary' => false];

const HEALTHY_SET = [['name' => '10.0.0.1:25060', 'stateStr' => 'PRIMARY', 'health' => 1], ['name' => '10.0.0.2:25060', 'stateStr' => 'SECONDARY', 'health' => 1], ['name' => '10.0.0.3:25060', 'stateStr' => 'REMOVED', 'health' => 1]];

it('raises the primary config above a member stranded on a newer forced config so it rejoins', function () {
    [$reconfigs, $run] = rejoinWith(['_id' => 'databasify', 'version' => 30207, 'members' => [['_id' => 3, 'host' => '10.0.0.9:25060']]], HEALTHY_SET);

    expect($run->output())->toContain('Syncing…')
        ->and($reconfigs)->toHaveCount(1)
        ->and($reconfigs[0]['on'])->toBe('10.0.0.1:25060')
        ->and($reconfigs[0]['force'])->toBeFalse()
        ->and($reconfigs[0]['replSetReconfig']['version'])->toBe(30208)
        ->and(array_column($reconfigs[0]['replSetReconfig']['members'], 'host'))->toBe(['10.0.0.1:25060', '10.0.0.2:25060', '10.0.0.3:25060']);
});

it('refuses at once to rejoin a member whose newer own config lists itself, since it may hold writes the set lacks', function () {
    [$reconfigs, $run] = rejoinWith(['_id' => 'databasify', 'version' => 30207, 'members' => [['_id' => 2, 'host' => '10.0.0.3:25060']]], HEALTHY_SET, succeeds: false);

    expect($run->successful())->toBeFalse()
        ->and($run->output().$run->errorOutput())->toContain('may hold writes the set does not have')
        ->and($reconfigs)->toBe([]);
});

it('refuses at once to raise the config without a healthy majority', function () {
    [$reconfigs, $run] = rejoinWith(['_id' => 'databasify', 'version' => 30207, 'members' => [['_id' => 3, 'host' => '10.0.0.9:25060']]], [['name' => '10.0.0.1:25060', 'stateStr' => 'PRIMARY', 'health' => 1], ['name' => '10.0.0.2:25060', 'stateStr' => '(not reachable/healthy)', 'health' => 0], ['name' => '10.0.0.3:25060', 'stateStr' => 'REMOVED', 'health' => 1]], succeeds: false);

    expect($run->successful())->toBeFalse()
        ->and($run->output().$run->errorOutput())->toContain('only 1 of 3 voters are primary or secondary')
        ->and($reconfigs)->toBe([]);
});

it('lets a normal initial sync wait without a reconfig or a primary connection per pass', function () {
    [$reconfigs, $run] = rejoinWith(SET_CONFIG, HEALTHY_SET, localHellos: [NOT_SERVING, NOT_SERVING, NOT_SERVING, ['ok' => 1, 'secondary' => true]], all: $commands);

    expect($run->successful())->toBeTrue($run->errorOutput())
        ->and($reconfigs)->toBe([])
        ->and(array_filter($commands, fn (array $command): bool => ($command['connect'] ?? null) === '10.0.0.1:25060'))->toHaveCount(1);
});

it('refuses at once to raise the config when the primary does not list the stranded member', function () {
    $withoutMember = [...SET_CONFIG, 'members' => array_slice(SET_CONFIG['members'], 0, 2)];
    [$reconfigs, $run] = rejoinWith(STRANDED_CONFIG, HEALTHY_SET, succeeds: false, set: $withoutMember, localHellos: [NOT_SERVING]);

    expect($run->successful())->toBeFalse()
        ->and($run->output().$run->errorOutput())->toContain('the primary does not list it')
        ->and(array_filter($reconfigs, fn (array $reconfig): bool => $reconfig['replSetReconfig']['version'] > 1000))->toBe([]);
});

it('fails ten minutes after raising the config when the member still has not installed it', function () {
    [$reconfigs, $run] = rejoinWith(STRANDED_CONFIG, HEALTHY_SET, succeeds: false, localHellos: [NOT_SERVING]);

    expect($run->successful())->toBeFalse()
        ->and($run->output().$run->errorOutput())->toContain('did not install the config of the set within 600s')
        ->and(array_column(array_column($reconfigs, 'replSetReconfig'), 'version'))->toBe([30208]);
});

it('refuses a member on a newer forced config that lists itself even when it already reports primary', function () {
    [$reconfigs, $run] = rejoinWith(['_id' => 'databasify', 'version' => 30207, 'members' => [['_id' => 2, 'host' => '10.0.0.3:25060']]], HEALTHY_SET, succeeds: false, localHellos: [['ok' => 1, 'isWritablePrimary' => true]]);

    expect($run->successful())->toBeFalse()
        ->and($run->output().$run->errorOutput())->toContain('may hold writes the set does not have')
        ->and($reconfigs)->toBe([]);
});

it('raises the latest primary config, not one a concurrent reconfig replaced while it waited', function () {
    $changed = [...SET_CONFIG, 'version' => 13, 'members' => [SET_CONFIG['members'][0], [...SET_CONFIG['members'][1], 'priority' => 2], SET_CONFIG['members'][2]]];
    [$reconfigs, $run] = rejoinWith(STRANDED_CONFIG, HEALTHY_SET, set: [SET_CONFIG, SET_CONFIG, $changed, $changed, $changed, $changed]);

    expect($run->successful())->toBeTrue($run->errorOutput())
        ->and($reconfigs)->toHaveCount(1)
        ->and($reconfigs[0]['replSetReconfig']['version'])->toBe(30208)
        ->and($reconfigs[0]['replSetReconfig']['members'][1]['priority'])->toBe(2);
});
