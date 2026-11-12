<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('runs the whole app in one container with a 30 minute stop grace period for Horizon', function () {
    $compose = Yaml::parseFile(base_path('scripts/compose.yaml'));

    expect(array_keys($compose['services']))->toBe(['app'])
        ->and($compose['services']['app']['stop_grace_period'])->toBe('30m');
});

it('sets no container environment in compose and keeps every former default in the env template', function () {
    $compose = Yaml::parseFile(base_path('scripts/compose.yaml'));
    $templateKeys = collect(file(base_path('scripts/env.example'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
        ->map(fn (string $line): string => explode('=', $line, 2)[0]);

    expect($compose['services']['app'])->not->toHaveKey('environment')
        ->and($templateKeys->all())->toContain('LOG_CHANNEL', 'QUEUE_CONNECTION', 'BROADCAST_CONNECTION', 'REDIS_HOST', 'REVERB_HOST', 'REVERB_PORT', 'REVERB_SCHEME', 'DATABASIFY_VERSION', 'HTTP_PORT');
});

it('shares the updater folder with the app', function () {
    $compose = Yaml::parseFile(base_path('scripts/compose.yaml'));

    expect($compose['services']['app']['volumes'])->toContain('./updater:/app/updater');
});
