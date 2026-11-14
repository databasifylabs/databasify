<?php

declare(strict_types=1);

use App\Engines\Drivers\Sentinel\SentinelFamilyEngine;
use App\Engines\Scripts\ScriptFailure;
use App\Engines\Scripts\ScriptView;
use App\Exceptions\Engines\EngineError;
use App\Exceptions\Engines\EngineUnavailable;
use Illuminate\Support\Facades\Process;

function runCli(string $engine, string $output, int $exit, bool $withPassword = true): array
{
    $root = sys_get_temp_dir().'/cli-'.uniqid();
    mkdir("{$root}/bin", recursive: true);
    mkdir("{$root}/conf");
    if ($withPassword) {
        file_put_contents("{$root}/conf/password", "  pa\"ss\n");
    }
    $cli = SentinelFamilyEngine::of($engine)->cli;
    file_put_contents("{$root}/bin/timeout", "#!/bin/bash\nshift\nexec \"\$@\"\n");
    file_put_contents("{$root}/bin/{$cli}", "#!/bin/bash\nprintf '%s\\n' \"\$@\" > \"\$STUB/args\"\nprintf '%s' \"\$REDISCLI_AUTH\" > \"\$STUB/auth\"\nprintf '%s' \"\$STUB_OUTPUT\"\nexit \"\$STUB_EXIT\"\n");
    chmod("{$root}/bin/timeout", 0755);
    chmod("{$root}/bin/{$cli}", 0755);
    $script = resolve(ScriptView::class)->render(idleDatabase($engine, 1)->nodes()->sole(), 'engines.testing.cli-run', ['engine' => SentinelFamilyEngine::of($engine)]);

    $run = Process::input($script)->env(['PATH' => "{$root}/bin:".getenv('PATH'), 'STUB' => $root, 'TEST_ROOT' => $root, 'STUB_OUTPUT' => $output, 'STUB_EXIT' => (string) $exit])->run(['bash', '-euo', 'pipefail', '-s']);
    $args = (string) @file_get_contents("{$root}/args");
    $auth = (string) @file_get_contents("{$root}/auth");
    Process::run(['rm', '-rf', $root]);

    return [$run, $args, $auth];
}

it('sends the trimmed password in REDISCLI_AUTH, never on argv', function (string $engine) {
    [$run, $args, $auth] = runCli($engine, "PONG\r\n", 0);

    expect($run->output())->toContain('DATABASIFY_RESULT PONG')
        ->and($auth)->toBe('pa"ss')
        ->and($args)->not->toContain('pa"ss')
        ->and($args)->toContain("--no-auth-warning\n--raw\n-e\n-t\n5\n-h\n127.0.0.1\n-p\n6379\nPING");
})->with(['valkey', 'redis']);

it('leaves out the connect timeout on a 7.2 CLI that rejects -t', function (string $engine) {
    $bin = sys_get_temp_dir().'/old-cli-'.uniqid();
    mkdir($bin);
    $cli = SentinelFamilyEngine::of($engine)->cli;
    file_put_contents("{$bin}/timeout", "#!/bin/bash\nshift\nexec \"\$@\"\n");
    file_put_contents("{$bin}/{$cli}", "#!/bin/bash\nfor arg in \"\$@\"; do if [ \"\$arg\" = -t ]; then echo \"Unrecognized option or bad number of args for: '-t'\"; exit 1; fi; done\nprintf 'PONG\\n'\n");
    chmod("{$bin}/timeout", 0755);
    chmod("{$bin}/{$cli}", 0755);
    $script = resolve(ScriptView::class)->render(idleDatabase($engine, 1)->nodes()->sole(), 'engines.testing.cli-run', ['engine' => SentinelFamilyEngine::of($engine)]);

    $run = Process::input($script)->env(['PATH' => "{$bin}:".getenv('PATH'), 'CLI_SECRET' => 'pw', 'TEST_ROOT' => $bin])->run(['bash', '-euo', 'pipefail', '-s']);
    Process::run(['rm', '-rf', $bin]);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())->and($run->output())->toContain('DATABASIFY_RESULT PONG');
})->with(['valkey', 'redis']);

it('maps CLI failures like the Go client', function (string $output, bool $withPassword, string $class, string $message) {
    [$run] = runCli('valkey', $output, 1, $withPassword);
    $error = ScriptFailure::of('engines.testing.cli-run', (int) $run->exitCode(), $run->output(), $run->errorOutput());

    expect($error)->toBeInstanceOf($class)->and($error->getMessage())->toContain($message);
})->with([
    'refused' => ['Could not connect to Valkey at 127.0.0.1:6379: Connection refused', true, EngineUnavailable::class, 'Connection refused'],
    'no password yet' => ['', false, EngineUnavailable::class, 'no password file yet'],
    'server error' => ['ERR unknown command', true, EngineError::class, 'ERR unknown command'],
]);

it('reads an empty CONFIG GET value as empty, not as a missing setting', function () {
    $bin = sys_get_temp_dir().'/cfg-'.uniqid();
    mkdir($bin);
    file_put_contents("{$bin}/timeout", "#!/bin/bash\nshift\nexec \"\$@\"\n");
    file_put_contents("{$bin}/valkey-cli", "#!/bin/bash\nname=\"\${@: -1}\"\nif [ \"\$name\" = notify-keyspace-events ]; then printf 'notify-keyspace-events\\n\\n'; else printf '%s\\n7\\n' \"\$name\"; fi\n");
    chmod("{$bin}/timeout", 0755);
    chmod("{$bin}/valkey-cli", 0755);
    $script = resolve(ScriptView::class)->render(idleDatabase('valkey', 1)->nodes()->sole(), 'engines.sentinel.config.show', ['engine' => SentinelFamilyEngine::of('valkey')]);

    $run = Process::input($script)->env(['PATH' => "{$bin}:".getenv('PATH'), 'CLI_SECRET' => 'pw', 'NAMES' => 'maxclients notify-keyspace-events'])->run(['bash', '-euo', 'pipefail', '-s']);
    Process::run(['rm', '-rf', $bin]);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('DATABASIFY_RESULT {"values":{"maxclients":"7","notify-keyspace-events":""}}');
});

it('finds no primary, without failing, on a first configure whose peers are not up yet', function () {
    $bin = sys_get_temp_dir().'/primary-'.uniqid();
    mkdir($bin);
    file_put_contents("{$bin}/timeout", "#!/bin/bash\nshift\nexec \"\$@\"\n");
    file_put_contents("{$bin}/valkey-cli", "#!/bin/bash\necho 'Could not connect to Valkey at 10.0.0.2:26379: Connection refused'\nexit 1\n");
    chmod("{$bin}/timeout", 0755);
    chmod("{$bin}/valkey-cli", 0755);
    $script = resolve(ScriptView::class)->render(idleDatabase('valkey', 1)->nodes()->sole(), 'engines.sentinel.nodes.primary', ['engine' => SentinelFamilyEngine::of('valkey')]);

    $run = Process::input($script)->env(['PATH' => "{$bin}:".getenv('PATH'), 'CLI_SECRET' => 'pw', 'PEERS' => '10.0.0.2 10.0.0.3'])->run(['bash', '-euo', 'pipefail', '-s']);
    Process::run(['rm', '-rf', $bin]);

    expect($run->successful())->toBeTrue($run->errorOutput())->and($run->output())->toContain('DATABASIFY_RESULT {"primary":""}');
});
