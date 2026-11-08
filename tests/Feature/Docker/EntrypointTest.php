<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

it('parses as POSIX sh', function () {
    $result = Process::run(['sh', '-n', base_path('docker/entrypoint.sh')]);

    expect($result->errorOutput())->toBe('')
        ->and($result->successful())->toBeTrue();
});

it('generates no secrets and sizes no Horizon workers inside the image', function () {
    $entrypoint = file_get_contents(base_path('docker/entrypoint.sh'));

    expect($entrypoint)->not->toContain('horizon-workers')->not->toContain('/dev/urandom')
        ->and(file_get_contents(base_path('docker/Dockerfile')))->not->toContain('horizon-workers');
});

it('runs the bundled Redis only when no external Redis is set', function (array $env, string $expected) {
    $result = Process::env($env + ['REDIS_HOST' => false, 'REDIS_URL' => false])->run(['sh', '-c', '. "$0" --functions-only; uses_local_redis && echo bundled || echo external', base_path('docker/entrypoint.sh')]);

    expect(trim($result->output()))->toBe($expected);
})->with([
    'no host set' => [[], 'bundled'],
    'loopback host' => [['REDIS_HOST' => '127.0.0.1'], 'bundled'],
    'localhost' => [['REDIS_HOST' => 'localhost'], 'bundled'],
    'external host' => [['REDIS_HOST' => 'redis.example.com'], 'external'],
    'redis url' => [['REDIS_URL' => 'redis://redis.example.com:6379'], 'external'],
]);
