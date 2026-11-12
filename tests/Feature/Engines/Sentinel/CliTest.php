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
    $script = resolve(ScriptView::class)->render(idleDatabase($engine, 1)->nodes()->sole(), 'testing/cli-run', ['engine' => SentinelFamilyEngine::of($engine)]);

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
        ->and($args)->toContain("--no-auth-warning\n--raw\n-e\n-h\n127.0.0.1\n-p\n6379\nPING");
})->with(['valkey', 'redis']);

it('maps CLI failures like the Go client', function (string $output, bool $withPassword, string $class, string $message) {
    [$run] = runCli('valkey', $output, 1, $withPassword);
    $error = ScriptFailure::of('testing/cli-run', (int) $run->exitCode(), $run->output(), $run->errorOutput());

    expect($error)->toBeInstanceOf($class)->and($error->getMessage())->toContain($message);
})->with([
    'refused' => ['Could not connect to Valkey at 127.0.0.1:6379: Connection refused', true, EngineUnavailable::class, 'Connection refused'],
    'no password yet' => ['', false, EngineUnavailable::class, 'no password file yet'],
    'server error' => ['ERR unknown command', true, EngineError::class, 'ERR unknown command'],
]);
