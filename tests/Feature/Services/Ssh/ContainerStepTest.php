<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Exceptions\Ssh\RemoteScriptFailed;
use App\Models\Database;
use App\Services\Ssh\Ssh;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

function engineStepOn(Database $database): void
{
    $node = $database->seed();

    $node->ssh()->exec($database->provider()->scriptView('lifecycle.status'), $database->provider()->scriptData()->for($node), 'Status');
}

function payloadOf(string $shim): string
{
    return base64_decode(Str::between($shim, "echo '", "' | base64 -d"), true) ?: '';
}

/**
 * @param  array<string, string>  $env
 * @return array{exit: ?int, output: string, exec: string, stdin: string}
 */
function runShimWithFakeDocker(string $shim, array $env): array
{
    $bin = sys_get_temp_dir().'/databasify-docker-'.Str::random(8);
    File::ensureDirectoryExists($bin);
    File::put("{$bin}/docker", <<<'BASH'
        #!/bin/bash
        case "$1" in
            inspect) echo "$FAKE_RUNNING" ;;
            exec) printf '%s\n' "$*" > "$FAKE_DIR/args"; cat > "$FAKE_DIR/stdin"; exit "$FAKE_EXIT" ;;
        esac
        BASH);
    chmod("{$bin}/docker", 0755);

    $result = Process::path($bin)->env(['PATH' => "{$bin}:".getenv('PATH'), 'FAKE_DIR' => $bin, ...$env])->input($shim)->run(['bash']);
    $run = [
        'exit' => $result->exitCode(),
        'output' => $result->output(),
        'exec' => File::exists("{$bin}/args") ? File::get("{$bin}/args") : '',
        'stdin' => File::exists("{$bin}/stdin") ? File::get("{$bin}/stdin") : '',
    ];
    File::deleteDirectory($bin);

    return $run;
}

it('sends engine steps through a host shim into the engine container', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $ssh = fakeSsh();

    engineStepOn($database);

    $node = $database->seed();
    $payload = payloadOf($ssh->scriptAt(0));
    expect($ssh->scriptAt(0))
        ->toStartWith("set -euo pipefail\nexport DEBIAN_FRONTEND=noninteractive\n")
        ->toContain("if ! container_running databasify-valkey; then\n    echo \"databasify-valkey is not running on this server\"\n    exit 69\nfi\n")
        ->toContain(" | base64 -d | docker exec -i databasify-valkey databasify step valkey.lines.9.lifecycle.status || step_status=\$?\n")
        ->and($payload)->toStartWith("set -euo pipefail\nexport DEBIAN_FRONTEND=noninteractive\n")
        ->toEndWith(view('ssh.engines.valkey.lines.9.lifecycle.status', $database->provider()->scriptData()->for($node))->render())
        ->and(bashErrorsIn($ssh->scriptAt(0)))->toBe('');
});

it('captures from a container without a log', function () {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    $ssh = fakeSsh()->respond("role=primary\n");

    $output = $database->seed()->server->ssh()->exec($database->provider()->scriptView('lifecycle.status'), $database->provider()->scriptData()->for());

    expect($output)->toBe("role=primary\n")
        ->and($ssh->scriptAt(0))->toContain("docker exec -i databasify-postgres databasify step postgres.lines.18.lifecycle.status || step_status=\$?\n")
        ->and($database->serverLogs()->exists())->toBeFalse();
});

it('keeps host scripts on the host and refuses an engine step without its container', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $server = $database->seed()->server;
    $ssh = fakeSsh();

    $server->ssh()->exec('ssh.databases.nodes.stop', $database->provider()->scriptData()->stopping(false));

    expect($ssh->scriptAt(0))->not->toContain('| base64 -d | docker exec -i')->toContain('docker stop databasify-valkey')
        ->and(fn () => $server->ssh()->exec('ssh.engines.valkey.lines.9.lifecycle.status', ['directory' => '/srv/valkey']))
        ->toThrow(LogicException::class, 'ssh.engines.valkey.lines.9.lifecycle.status runs inside the engine container, but no container was named for it.')
        ->and($ssh->runs)->toHaveCount(1);
});

it('fails the step when the container is not running', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    fakeSsh()->respond("databasify-valkey is not running on this server\n", Ssh::CONTAINER_NOT_RUNNING);

    expect(fn () => engineStepOn($database))->toThrow(RemoteScriptFailed::class, 'failed with exit code 69.');
    expect($database->serverLogs()->sole())
        ->name->toBe('Status')
        ->exit_code->toBe(69)
        ->output->toContain('databasify-valkey is not running on this server');
});

it('exits 69 only when the container is down and passes any other step exit code through', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $ssh = fakeSsh();
    $database->seed()->server->ssh()->exec($database->provider()->scriptView('lifecycle.status'), $database->provider()->scriptData()->for());
    $shim = $ssh->scriptAt(0);

    $stopped = runShimWithFakeDocker($shim, ['FAKE_RUNNING' => 'false', 'FAKE_EXIT' => '0']);
    $failing = runShimWithFakeDocker($shim, ['FAKE_RUNNING' => 'true', 'FAKE_EXIT' => '7']);
    $failingWith69 = runShimWithFakeDocker($shim, ['FAKE_RUNNING' => 'true', 'FAKE_EXIT' => '69']);

    expect($stopped)->toBe(['exit' => 69, 'output' => "databasify-valkey is not running on this server\n", 'exec' => '', 'stdin' => ''])
        ->and($failing)->toBe(['exit' => 7, 'output' => '', 'exec' => "exec -i databasify-valkey databasify step valkey.lines.9.lifecycle.status\n", 'stdin' => payloadOf($shim)])
        ->and($failingWith69['exit'])->toBe(1)
        ->and($shim)->not->toContain('docker start');
});

it('starts a stopped container first when the step asks for it, as recovery does', function () {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    $ssh = fakeSsh();
    $database->seed()->server->ssh()->exec($database->provider()->scriptView('lifecycle.wake'), [...$database->provider()->scriptData()->for(), Ssh::START_CONTAINER => true]);

    expect($ssh->scriptAt(0))
        ->toContain("if ! container_running databasify-postgres; then\n    if ! docker start databasify-postgres > /dev/null; then\n        echo \"databasify-postgres could not start on this server\"\n        exit 69\n    fi\n")
        ->toContain("until docker exec databasify-postgres test -S /run/databasify.sock 2> /dev/null; do\n        if [ \"\$waited\" -ge 60 ]; then\n            echo \"databasify-postgres started, but its supervisor did not come up within 60s\"\n            exit 69\n")
        ->and(bashErrorsIn($ssh->scriptAt(0)))->toBe('');
});
