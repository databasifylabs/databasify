<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

it('keeps the banner with its chip inside 80 columns', function () {
    $output = installerScript("banner 'Welcome to the Databasify installer' 'This sets up Docker.' ' v0.4.2 → v0.5.0 '")->output();

    expect(collect(explode("\n", $output))->map(fn (string $line): int => mb_strlen($line))->max())->toBeLessThanOrEqual(80)
        ->and($output)->toContain('v0.4.2 → v0.5.0', 'Welcome to the Databasify installer');
});

it('prints no escape codes without a terminal', function () {
    $output = installerScript("banner T D ' v1 '; heading Server; done_line 'Linux'; summary_box 'Databasify is installed  in 48s' 'Open|http://203.0.113.10'")->output();

    expect($output)->not->toContain("\033");
});

it('logs a step\'s output and prints its result label', function () {
    $log = temporaryFile();

    $result = installerScript("LOG=$log; noisy() { echo noisy; step_result 'Docker 27.3.1 installed'; }; run_step 'Installing Docker' 'about a minute' noisy");

    expect($result->output())->toContain('✓ Docker 27.3.1 installed')->not->toContain('noisy')
        ->and(file_get_contents($log))->toContain('noisy');
});

it('shows the reason, the end of the log, the retry command and the log path when a step fails', function () {
    $log = temporaryFile();

    $result = installerScript("LOG=$log; RETRY_COMMAND='curl -fsSL https://get.databasify.io/install.sh | sudo bash'; run_step 'Pulling images' '' sh -c 'echo manifest unknown; exit 3'");

    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toContain('✗ Pulling images failed', 'manifest unknown', 'Retry    curl -fsSL https://get.databasify.io/install.sh | sudo bash', "Full log $log");
});

it('leaves out the log and retry lines when there is no log or retry command', function () {
    $result = installerScript("LOG=/dev/null; step_failed 'Nothing works'");

    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toContain('✗ Nothing works')->not->toContain('Full log', 'Retry');
});

it('draws a summary box whose lines share one width', function () {
    $lines = collect(explode("\n", rtrim(installerScript("summary_box 'Databasify is installed  in 48s' 'Open|http://203.0.113.10' 'Next|Create your admin account'")->output())))->filter();

    expect($lines->map(fn (string $line): int => mb_strlen($line))->unique())->toHaveCount(1)
        ->and($lines->implode("\n"))->toContain('╭', '╰', 'Open        http://203.0.113.10');
});

it('formats elapsed time', function (int $seconds, string $expected) {
    expect(trim(installerScript('elapsed_since $(( $(date +%s) - '.$seconds.' ))')->output()))->toBe($expected);
})->with([[48, '48s'], [134, '2m 14s']]);

it('prints no colour on a terminal when NO_COLOR is set', function () {
    $snippet = 'SOURCE='.base_path('scripts').'; . '.base_path('scripts/lib.sh').'; banner T D " v1 "; done_line Linux';

    $output = Process::env(['NO_COLOR' => '1'])->run(['script', '-q', '/dev/null', 'bash', '-c', $snippet])->output();

    expect($output)->toContain('Linux')->not->toContain("\033[38;2");
})->skip(fn () => PHP_OS_FAMILY !== 'Darwin', 'needs the BSD script command for a pty');

it('refuses with the reason, the untouched notice and the detail', function () {
    $result = installerScript("refuse 'Fedora 41 is not supported' 'Databasify runs on Ubuntu or Debian.'");

    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toContain('✗ Fedora 41 is not supported', 'Nothing was changed on this server.', 'Databasify runs on Ubuntu or Debian.');
});

it('prints a skipped step', function () {
    expect(installerScript("skipped_line 'Docker 27.3.1 already installed'")->output())
        ->toContain('– Docker 27.3.1 already installed (skipped)');
});

it('stops the running command and exits 130 when the installer is terminated', function () {
    $result = installerScript("( sleep 0.5; kill -TERM \$\$ ) & run_step 'Sleeping' '' sleep 31.7");
    usleep(300000);

    expect($result->exitCode())->toBe(130)
        ->and(Process::run(['pgrep', '-f', 'sleep 31.7'])->output())->toBe('');
});

it('stops the grandchildren of a running step when the installer is terminated', function () {
    $result = installerScript("( sleep 0.5; kill -TERM \$\$ ) & slow_step() { sleep 32.3; }; run_step 'Sleeping' '' slow_step");
    usleep(300000);

    expect($result->exitCode())->toBe(130)
        ->and(Process::run(['pgrep', '-f', 'sleep 32.3'])->output())->toBe('');
});
