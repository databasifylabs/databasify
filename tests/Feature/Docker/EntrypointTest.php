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
