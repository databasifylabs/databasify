<?php

declare(strict_types=1);

use App\Engines\Data\MigrationSource;
use App\Engines\Data\MigrationStart;
use App\Engines\Drivers\MySqlFamily\MySqlFamilyEngine;
use App\Engines\Scripts\ScriptView;
use App\Models\DatabaseNode;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * @param  array<string, string>  $stubs
 */
function runJobLocally(DatabaseNode $node, string $job, array $data, array $stubs, array $env = []): string
{
    $directory = sys_get_temp_dir().'/job-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists("{$directory}/bin");

    foreach (['setpriv' => "#!/bin/bash\nwhile [[ \$1 == --* ]]; do shift; done\nexec \"\$@\"\n", 'timeout' => "#!/bin/bash\nshift\nexec \"\$@\"\n", ...$stubs] as $name => $body) {
        File::put("{$directory}/bin/{$name}", $body);
        chmod("{$directory}/bin/{$name}", 0755);
    }

    $run = Process::input(resolve(ScriptView::class)->render($node, $job, $data))
        ->env([...$env, 'PATH' => "{$directory}/bin:".getenv('PATH'), 'STUB_DIR' => $directory])
        ->run(['bash', '-s']);
    $log = File::exists("{$directory}/commands.log") ? File::get("{$directory}/commands.log") : '';
    File::deleteDirectory($directory);

    expect($run->successful())->toBeTrue($run->errorOutput());

    return $log;
}

/**
 * @return array<string, array{mode: string, content: string}>
 */
function tabFilesOf(string $files): array
{
    return collect(explode("\n", $files))
        ->mapWithKeys(fn (string $line): array => [explode("\t", $line)[0] => ['mode' => explode("\t", $line)[1], 'content' => (string) base64_decode(explode("\t", $line)[2])]])
        ->all();
}

it('schedules the postgres backup as a bash job', function () {
    $scripts = fakeScripts()->respond('postgres/nodes/configure', ['changed' => true]);
    fakeSsh()->respondToEngine('file get', ['contentBase64' => '', 'exists' => false]);
    $node = engineNode();
    $node->database->update(['version_line' => '18']);

    $node->fresh()->driver()->nodes()->configure(nodeConfigFromFixture(goFixture('configure-primary.json')['request']));

    $scripts->assertRan('postgres/nodes/configure', function (string $script, array $env): bool {
        $files = collect(json_decode($env['FILES'], true))->keyBy('path');
        $schedule = json_decode((string) base64_decode($env['MANIFEST']), true)['schedules'][0];

        return $schedule['argv'] === ['bash', '/srv/postgres/conf/backup.sh']
            && $files['/srv/postgres/conf/backup.sh']['mode'] === '700'
            && str_contains((string) base64_decode($files['/srv/postgres/conf/backup.sh']['content']), 'backup');
    });
});

it('schedules the mongodb backup as a bash job with its storage in env', function () {
    $scripts = fakeScripts()->respond('mongodb/nodes/configure', ['changed' => true]);
    fakeSsh()->respondToEngine('file get', ['contentBase64' => '', 'exists' => false]);
    $node = idleDatabase('mongodb', 1, versionLine: '8.0')->nodes()->sole();
    $request = goFixture('configure-archived.json', 'mongodb')['request'];

    $node->driver()->nodes()->configure(nodeConfigFromFixture($request));

    $scripts->assertRan('mongodb/nodes/configure', function (string $script, array $env) use ($request): bool {
        $files = tabFilesOf($env['FILES']);
        $schedule = json_decode((string) base64_decode($env['MANIFEST']), true)['schedules'][0];

        return $schedule['argv'] === ['bash', '/srv/mongodb/conf/backup.sh']
            && $schedule['env']['RETENTION_DAYS'] === (string) $request['archive']['retentionDays']
            && str_contains($schedule['env']['PBM_MONGODB_URI'], 'databasify_pbm:')
            && str_contains($schedule['env']['PBM_CONFIG'], '"bucket"')
            && $files['/srv/mongodb/conf/backup.sh']['mode'] === '700'
            && str_contains($files['/srv/mongodb/conf/backup.sh']['content'], 'pbm_locked backup');
    });
});

it('starts the mongodb migration copy as a bash job', function () {
    $scripts = fakeScripts()->respond('mongodb/migration/start');
    $node = idleDatabase('mongodb', 1, versionLine: '8.0')->nodes()->sole();

    $node->driver()->migration()->start(new MigrationStart(new MigrationSource('src.example.com', 27017, 'reader', 'p@ss:w/rd', true), ['shop'], false, null));

    $scripts->assertRan('mongodb/migration/start', function (string $script, array $env): bool {
        return str_contains((string) base64_decode($env['COPY_SCRIPT']), 'mongodump')
            && $env['SOURCE_URI'] === 'mongodb://reader:p%40ss%3Aw%2Frd@src.example.com:27017/?authSource=admin&tls=true&tlsInsecure=true'
            && str_starts_with($env['TARGET_URI'], 'mongodb://root:')
            && $env['MIGRATION_DATABASES'] === 'shop'
            && ! str_contains($script, 'engine-task');
    });
});

it('renders every job script as valid bash', function (string $engine, string $job) {
    $node = idleDatabase($engine, 1)->nodes()->sole();

    expect(bashErrorsIn(resolve(ScriptView::class)->render($node, $job, ['port' => 25060])))->toBe('');
})->with([
    ['postgres', 'postgres/jobs/backup'],
    ['mongodb', 'mongodb/jobs/backup'],
    ['mongodb', 'mongodb/jobs/migration-copy'],
]);

it('takes a diff backup when a recent full one exists and a full one otherwise', function (int $daysSinceFull, string $kind) {
    $node = idleDatabase('postgres', 1)->nodes()->sole();
    $stop = now()->subDays($daysSinceFull)->getTimestamp();

    $log = runJobLocally($node, 'postgres/jobs/backup', ['port' => 25060], [
        'psql' => "#!/bin/bash\ncat > /dev/null\necho f\n",
        'pgbackrest' => "#!/bin/bash\nprintf 'pgbackrest %s\\n' \"\$*\" >> \"\$STUB_DIR/commands.log\"\nif [[ \$* == *info* ]]; then echo '[{\"backup\":[{\"type\":\"full\",\"timestamp\":{\"stop\":{$stop}}}]}]'; fi\n",
    ]);

    expect($log)->toContain('stanza-create')->toContain("--type={$kind} backup");
})->with([[1, 'diff'], [7, 'full']]);

it('skips the postgres backup on a standby', function () {
    $node = idleDatabase('postgres', 1)->nodes()->sole();

    $log = runJobLocally($node, 'postgres/jobs/backup', ['port' => 25060], [
        'psql' => "#!/bin/bash\ncat > /dev/null\necho t\n",
        'pgbackrest' => "#!/bin/bash\nprintf 'pgbackrest %s\\n' \"\$*\" >> \"\$STUB_DIR/commands.log\"\n",
    ]);

    expect($log)->toBe('');
});

/**
 * @param  array<string, string>  $replies
 * @param  array<string, string>  $files
 * @param  array<string, string>  $stubs
 * @param  array<string, string>  $env
 * @param  array<string, int>  $modifiedAt
 * @return array{sql: string, commands: string, root: string}
 */
function runFamilyJobLocally(string $engine, string $job, array $replies, array $files = [], array $stubs = [], array $env = [], array $modifiedAt = []): array
{
    $node = idleDatabase($engine, 1)->nodes()->sole();
    $directory = sys_get_temp_dir().'/family-job-'.bin2hex(random_bytes(6));
    $root = "{$directory}/root";
    File::ensureDirectoryExists("{$directory}/bin");
    File::ensureDirectoryExists("{$directory}/rules");

    foreach ($files as $path => $content) {
        File::ensureDirectoryExists(dirname("{$root}/{$path}"));
        File::put("{$root}/{$path}", $content);
    }

    foreach ($modifiedAt as $path => $time) {
        touch("{$root}/{$path}", $time);
    }

    $index = 0;

    foreach ($replies as $needle => $reply) {
        File::put(sprintf('%s/rules/%03d', $directory, $index++), "{$needle}\n{$reply}");
    }

    $client = "#!/bin/bash\nsql=\$(cat)\nprintf '%s\\n-- end --\\n' \"\$sql\" >> \"\$STUB_DIR/sql.log\"\nfor rule in \"\$STUB_DIR\"/rules/*; do\n    [ -e \"\$rule\" ] || continue\n    if grep -qF -- \"\$(head -n 1 \"\$rule\")\" <<< \"\$sql\"; then\n        tail -n +2 \"\$rule\"\n        exit 0\n    fi\ndone\n";
    $defaults = [
        'mysql' => $client,
        'mariadb' => $client,
        'timeout' => "#!/bin/bash\nshift\nexec \"\$@\"\n",
        'rclone' => "#!/bin/bash\nprintf 'rclone %s\\n' \"\$*\" >> \"\$STUB_DIR/commands.log\"\n[ \"\$1\" != rcat ] || cat > /dev/null\n",
        'sleep' => "#!/bin/bash\nkill -TERM \"\$PPID\"\n",
    ];

    foreach ([...$defaults, ...$stubs] as $name => $body) {
        File::put("{$directory}/bin/{$name}", $body);
        chmod("{$directory}/bin/{$name}", 0755);
    }

    $script = str_replace("/srv/{$engine}", $root, resolve(ScriptView::class)->render($node, $job, ['engine' => MySqlFamilyEngine::of($engine)]));
    $run = Process::input($script)
        ->env([...$env, 'PATH' => "{$directory}/bin:".getenv('PATH'), 'STUB_DIR' => $directory, 'RCLONE_REMOTE' => ':s3:bucket/', 'STORAGE_PATH' => 'archive'])
        ->run(['bash', '-s']);
    $result = [
        'sql' => File::exists("{$directory}/sql.log") ? File::get("{$directory}/sql.log") : '',
        'commands' => File::exists("{$directory}/commands.log") ? File::get("{$directory}/commands.log") : '',
        'root' => collect(File::allFiles($root))->map(fn (SplFileInfo $file): string => str($file->getPathname())->after("{$root}/")->value())->sort()->values()->implode("\n"),
    ];
    File::deleteDirectory($directory);

    expect($run->successful())->toBeTrue($run->errorOutput());

    return $result;
}

it('runs the mysql binlog stream, shipper and backup as bash jobs', function (string $engine, string $fixture, string $line) {
    $scripts = fakeScripts()->respond('mysql-family/nodes/configure', ['changed' => true]);
    fakeSsh()->respondToEngine('file get', ['contentBase64' => '', 'exists' => false]);
    $node = idleDatabase($engine, 1, versionLine: $line)->nodes()->sole();
    $request = goFixture($fixture, $engine)['request'];

    $node->driver()->nodes()->configure(nodeConfigFromFixture($request));

    $scripts->assertRan('mysql-family/nodes/configure', function (string $script, array $env) use ($engine): bool {
        $files = collect(json_decode($env['FILES'], true))->keyBy('path');
        $manifest = json_decode((string) base64_decode($env['MANIFEST']), true);
        $stream = collect($manifest['processes'])->firstWhere('name', "{$engine}-binlog-stream");
        $schedules = collect($manifest['schedules'])->keyBy('name');

        return $stream['argv'] === ['bash', "/srv/{$engine}/conf/binlog-stream.sh"]
            && ctype_digit($stream['env']['STREAM_SERVER_ID'])
            && $schedules["{$engine}-binlog-ship"]['argv'] === ['bash', "/srv/{$engine}/conf/binlog-ship.sh"]
            && $schedules["{$engine}-backup"]['argv'] === ['bash', "/srv/{$engine}/conf/backup.sh"]
            && $schedules["{$engine}-backup"]['env']['RETENTION_DAYS'] !== ''
            && isset($schedules["{$engine}-binlog-ship"]['env']['RCLONE_S3_SECRET_ACCESS_KEY'])
            && collect(['backup', 'binlog-ship', 'binlog-stream'])->every(fn (string $job): bool => $files["/srv/{$engine}/conf/{$job}.sh"]['mode'] === '700');
    });
})->with([['mysql', 'configure-primary-9.7.json', '9.7'], ['mariadb', 'configure-galera-11.8.json', '11.8']]);

it('renders every mysql family job as valid bash', function (string $engine, string $job) {
    $node = idleDatabase($engine, 1)->nodes()->sole();

    expect(bashErrorsIn(resolve(ScriptView::class)->render($node, $job, ['engine' => MySqlFamilyEngine::of($engine)])))->toBe('');
})->with([
    ['mysql', 'mysql-family/jobs/backup'], ['mysql', 'mysql-family/jobs/binlog-ship'], ['mysql', 'mysql-family/jobs/binlog-stream'], ['mysql', 'mysql-family/jobs/migration-copy'],
    ['mariadb', 'mysql-family/jobs/backup'], ['mariadb', 'mysql-family/jobs/binlog-ship'], ['mariadb', 'mysql-family/jobs/binlog-stream'], ['mariadb', 'mysql-family/jobs/migration-copy'],
    ['mariadb', 'mariadb/jobs/cluster-watch'],
]);

it('ships only closed binlogs', function () {
    $run = runFamilyJobLocally('mysql', 'mysql-family/jobs/binlog-ship', ['FLUSH BINARY LOGS' => '', '@@server_uuid' => 'uuid-1'], [
        'archive/binlogs/bin.000001' => 'closed',
        'archive/binlogs/bin.000002' => 'open',
        'state/binlog-rotated' => 'bin.000002 4',
    ]);

    expect($run['commands'])->toContain('rclone copyto')->toContain('bin.000001')->toContain('binlogs/uuid-1/bin.000001')->not->toContain('bin.000002')
        ->and($run['root'])->toContain('archive/binlogs/bin.000002')->not->toContain('archive/binlogs/bin.000001');
});

it('never ships the binlog the stream is still writing, even below a stale higher one', function () {
    $run = runFamilyJobLocally('mysql', 'mysql-family/jobs/binlog-ship', ['FLUSH BINARY LOGS' => '', '@@server_uuid' => 'uuid-1'], [
        'archive/binlogs/bin.000001' => 'being written',
        'archive/binlogs/bin.000009' => 'stale',
        'state/binlog-rotated' => 'bin.000001 13',
    ], modifiedAt: ['archive/binlogs/bin.000009' => time() - 3600, 'archive/binlogs/bin.000001' => time()]);

    expect($run['commands'])->toContain('binlogs/uuid-1/bin.000009')->not->toContain('bin.000001')
        ->and($run['root'])->toContain('archive/binlogs/bin.000001');
});

it('expires bases older than the retention days', function () {
    $now = now()->utc();
    $stamp = fn (int $days): string => $now->copy()->subDays($days)->format('Ymd\THis\Z');
    $listing = json_encode(collect([1, 8, 15])->flatMap(fn (int $days): array => [['Path' => "{$stamp($days)}.xbstream"], ['Path' => "{$stamp($days)}.binlog-info"]])->all());

    $run = runFamilyJobLocally('mysql', 'mysql-family/jobs/backup', ['MEMBER_ROLE' => 'PRIMARY'], [], [
        'xtrabackup' => "#!/bin/bash\necho backup\nfor arg; do [[ \$arg != --extra-lsndir=* ]] || touch \"\${arg#*=}/xtrabackup_info\"; done\n",
        'rclone' => "#!/bin/bash\nprintf 'rclone %s\\n' \"\$*\" >> \"\$STUB_DIR/commands.log\"\n[ \"\$1\" != rcat ] || cat > /dev/null\n[ \"\$1\" != lsjson ] || echo '{$listing}'\n",
    ], ['RETENTION_DAYS' => '7']);

    expect($run['commands'])->toContain("deletefile :s3:bucket/archive/base/{$stamp(15)}.xbstream")
        ->toContain("deletefile :s3:bucket/archive/base/{$stamp(15)}.binlog-info")
        ->not->toContain("deletefile :s3:bucket/archive/base/{$stamp(8)}")
        ->not->toContain("deletefile :s3:bucket/archive/base/{$stamp(1)}");
});

it('keeps the galera writer on the lowest live node', function () {
    $run = runFamilyJobLocally('mariadb', 'mariadb/jobs/cluster-watch', [
        'WSREP_CLUSTER_STATUS' => "1\t1\tPrimary\tSynced\t0",
        'databasify.writer WHERE' => '10.0.0.9',
        'WSREP_INCOMING_ADDRESSES' => '10.0.0.3:3306,10.0.0.2:3306',
    ], ['state/node.json' => json_encode(['clustered' => true, 'readOnly' => false, 'ip' => '10.0.0.2', 'members' => []])]);

    expect($run['sql'])->toContain("REPLACE INTO databasify.writer VALUES (1, '10.0.0.2')")
        ->toContain('SET GLOBAL read_only = OFF');
});
