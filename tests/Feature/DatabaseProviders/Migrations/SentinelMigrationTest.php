<?php

declare(strict_types=1);

use App\DTOs\Migrations\MigrationSource;
use App\Enums\Migrations\MigrationMode;
use App\Enums\Migrations\MigrationPhase;
use App\Models\Database;
use Illuminate\Support\Facades\Process;

dataset('sentinel engines', [
    'valkey' => ['valkey', '/usr/local/bin/valkey-cli', 'VALKEY_PASSWORD'],
    'redis' => ['redis', '/usr/local/bin/redis-cli', 'REDIS_PASSWORD'],
]);

it('takes optional credentials and no databases', function (string $engine) {
    expect(Database::providerFor($engine)->migration()->form()->toArray())->toBe(['default_port' => 6379, 'credentials_required' => false, 'selects_databases' => false]);
})->with('sentinel engines');

it('checks the source with the flavor cli and optional acl user', function (string $engine, string $cli) {
    $database = migratingDatabase($engine);
    $ssh = fakeSsh()
        ->respond("redis_version=7.0.15\nvalkey_version=8.1.0\nused_memory_human=2.5M\nrole=slave\nkeys=12\n")
        ->respond("error=\n");

    $report = $database->provider()->migration()->check(new MigrationSource('cache.example.com', 6380, "o'neil", 'top-secret'));
    $silent = $database->provider()->migration()->check(new MigrationSource('cache.example.com', 6380, '', ''));

    expect($report->facts)->toBe(['version' => '8.1.0', 'keys' => '12', 'used_memory' => '2.5M', 'role' => 'slave'])
        ->and($report->blockers)->toBe([])
        ->and($ssh->scriptAt(0))->toContain("timeout 15 {$cli} -h cache.example.com -p 6380 --user 'o'\\''neil' --tls --insecure")
        ->not->toContain('top-secret')
        ->and($ssh->scriptAt(1))->not->toContain('--user')
        ->and($silent->error)->toBe('No answer from the source.');
})->with('sentinel engines');

it('connects to a migration source over tls without verifying its certificate', function (string $engine) {
    $database = migratingDatabase($engine);
    $ssh = fakeSsh()->respond("redis_version=7.0.15\nkeys=1\n");

    $database->provider()->migration()->check(new MigrationSource('cache.example.com', 6380, '', ''));

    expect($ssh->scriptAt(0))->toContain('--tls --insecure --no-auth-warning');
})->with('sentinel engines');

it('still reports a source that refuses the tls handshake', function (string $engine) {
    $database = migratingDatabase($engine);
    fakeSsh()->respond("error=Could not connect to Redis at cache.example.com:6380: SSL_connect failed\n");

    $report = $database->provider()->migration()->check(new MigrationSource('cache.example.com', 6380, '', ''));

    expect($report->error)->toBe('Could not connect to Redis at cache.example.com:6380: SSL_connect failed');
})->with('sentinel engines');

it('starts redis-shake writing into the local primary with the database password', function (string $engine, string $cli, string $secret) {
    $database = migratingDatabase($engine);
    $ssh = fakeSsh();

    $database->provider()->migration()->start(migrationOf($database, MigrationMode::OneTime));

    $config = base64_decode((string) preg_replace('/.*echo \'([A-Za-z0-9+\/=]+)\' \| base64 -d > "\$dir\/migration\/shake.toml".*/s', '$1', $ssh->scriptAt(0)));

    expect($ssh->scriptAt(0))->toContain("docker exec -i databasify-{$engine} databasify step {$engine}.lines.{$database->version_line}.migrations.start")
        ->toContain("dir=/srv/{$engine}")
        ->toContain("databasify ctl run --quiet --remain-after-exit --unit={$engine}-migration --property=WorkingDirectory=\"\$dir/migration\" /usr/local/bin/redis-shake")
        ->not->toContain('curl')
        ->toContain('echo "copying from db.example.com:3306 in the background"')
        ->and($config)->toContain('[scan_reader]')
        ->toContain('address = "db.example.com:3306"')
        ->toContain('password = "'.$database->settings->secret($secret).'"')
        ->toContain('tls = true')
        ->toContain("dir = \"/srv/{$engine}/migration/work\"");
})->with('sentinel engines');

it('reads redis-shake progress from its supervised process in the container', function (string $engine) {
    $database = migratingDatabase($engine);
    $continuous = migrationOf($database, MigrationMode::Continuous);
    $oneTime = migrationOf($database, MigrationMode::OneTime);
    $ssh = fakeSsh()
        ->respond("unit=missing\n")
        ->respond("running=true\nexit_code=0\nconsistent=false\nlog=syncing rdb 40%\n")
        ->respond("running=true\nexit_code=0\nconsistent=true\nlog=syncing aof, diff=[0]\n")
        ->respond("running=false\nexit_code=0\nconsistent=false\nlog=done\n")
        ->respond("running=false\nexit_code=0\nconsistent=false\nlog=ERR unknown command 'SYNC'\n");
    $driver = $database->provider();

    $missing = $driver->migration()->progress($continuous);
    $copying = $driver->migration()->progress($continuous);
    $synced = $driver->migration()->progress($continuous);
    $copied = $driver->migration()->progress($oneTime);
    $refused = $driver->migration()->progress($continuous);

    expect($missing)->phase->toBe(MigrationPhase::Broken)->detail->toBe('redis-shake is no longer on the server.')
        ->and($copying)->phase->toBe(MigrationPhase::Working)->detail->toBe('Copying from the source: syncing rdb 40%')
        ->and($synced)->phase->toBe(MigrationPhase::CaughtUp)->lagSeconds->toBe(0)
        ->and($copied)->phase->toBe(MigrationPhase::CaughtUp)->detail->toBe('Copied every key from the source.')
        ->and($refused)->phase->toBe(MigrationPhase::Broken)->detail->toContain("try a one-time migration): ERR unknown command 'SYNC'")
        ->and($ssh->scriptAt(0))->toContain("databasify ctl show -p \"\$1\" --value {$engine}-migration")->toContain("databasify ctl logs --unit {$engine}-migration --lines 3 --output cat");
})->with('sentinel engines');

it('needs no extra step to follow and stops the unit when stopping', function (string $engine) {
    $database = migratingDatabase($engine);
    $migration = migrationOf($database, MigrationMode::Continuous);
    $ssh = fakeSsh();

    $database->provider()->migration()->follow($migration);
    expect($ssh->runs)->toBe([]);

    $database->provider()->migration()->stop($migration);
    expect($ssh->scriptAt(0))->toContain("databasify ctl stop {$engine}-migration")->toContain('rm -rf "$dir/migration"')->toContain("dir=/srv/{$engine}");
})->with('sentinel engines');

it('warns that continuous redis migrations stop at module keys and that search indexes are not copied', function () {
    $database = migratingDatabase('redis');
    $ssh = fakeSsh()
        ->respond("redis_version=8.10.3\nrole=master\nkeys=11\nmodules=ReJSON,search,vectorset\nsearch_number_of_indexes=2\n")
        ->respond("redis_version=8.10.3\nrole=master\nkeys=11\nmodules=\n");

    $withModules = $database->provider()->migration()->check(new MigrationSource('cache.example.com', 6380, '', ''));
    $plain = $database->provider()->migration()->check(new MigrationSource('cache.example.com', 6380, '', ''));

    expect($withModules->warnings)->toBe([
        'The source runs modules (ReJSON,search,vectorset). A continuous migration stops at the first key of a module type; a one-time migration copies those keys.',
        'The source has 2 search indexes. Their keys are copied but the indexes are not: create them again with FT.CREATE on this database.',
    ])
        ->and($withModules->supportsContinuous())->toBeTrue()
        ->and($plain->warnings)->toBe([])
        ->and($ssh->scriptAt(0))->toContain("grep -oE '^module:name=[^,]+'")->toContain('<<< "$(source_info search_indexes)"')
        ->and(bashErrorsIn($ssh->scriptAt(0)))->toBe('');
});

it('names the key redis-shake could not read in its last run when a continuous redis migration stops', function (string $log, string $detail) {
    $root = sys_get_temp_dir().'/shake-'.uniqid();
    mkdir($root, 0755, true);
    file_put_contents("{$root}/log", $log);
    file_put_contents("{$root}/databasify", "#!/bin/sh\ncase \"\$2\" in\n  show) [ \"\$4\" = LoadState ] && echo loaded || echo dead ;;\n  logs) cat {$root}/log ;;\nesac\n");
    chmod("{$root}/databasify", 0755);
    $script = view('ssh.engines.redis.migrations.progress', Database::providerFor('redis')->scriptVariables())->render();
    $output = Process::env(['PATH' => "{$root}:".getenv('PATH')])->input($script)->run(['bash', '-euo', 'pipefail'])->output();
    Process::run(['rm', '-rf', $root]);
    $database = migratingDatabase('redis');
    fakeSsh()->respond($output);

    expect($database->provider()->migration()->progress(migrationOf($database, MigrationMode::Continuous)))
        ->phase->toBe(MigrationPhase::Broken)
        ->detail->toBe($detail);
})->with([
    'module type' => [
        "databasify: started /usr/local/bin/redis-shake (pid 7)\n\e[31mERR\e[0m unsupported module type: TSDB-TYPE\n  goexit()\ndatabasify: exited with status 1\n",
        'redis-shake cannot stream the TSDB-TYPE keys, a Redis module type, so the continuous migration stopped. Run a one-time migration instead: it copies every key with DUMP and RESTORE.',
    ],
    'newer rdb type in the last run only' => [
        "databasify: started /usr/local/bin/redis-shake (pid 7)\nERR unsupported module type: TSDB-TYPE\ndatabasify: started /usr/local/bin/redis-shake (pid 9)\nERR unknown rdb value type byte. key=[s:stream], type=[27]\ndatabasify: exited with status 1\n",
        'redis-shake cannot stream the key s:stream (RDB type 27, newer than redis-shake reads), so the continuous migration stopped. Run a one-time migration instead: it copies every key with DUMP and RESTORE.',
    ],
    'other failure' => [
        "databasify: started /usr/local/bin/redis-shake (pid 7)\nERR unknown command 'SYNC'\ndatabasify: exited with status 1\n",
        "redis-shake stopped (the source may not allow SYNC, try a one-time migration): databasify: started /usr/local/bin/redis-shake (pid 7) ERR unknown command 'SYNC' databasify: exited with status 1",
    ],
]);
