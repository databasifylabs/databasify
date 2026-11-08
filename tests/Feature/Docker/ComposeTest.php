<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('gives horizon a stop grace period of 30 minutes', function () {
    $compose = Yaml::parseFile(base_path('scripts/compose.yaml'));

    expect($compose['services']['horizon']['stop_grace_period'])->toBe('30m');
});

it('sets no container environment in compose and keeps every former default in the env template', function () {
    $compose = Yaml::parseFile(base_path('scripts/compose.yaml'));
    $templateKeys = collect(file(base_path('scripts/env.example'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
        ->map(fn (string $line): string => explode('=', $line, 2)[0]);

    expect(collect($compose['services'])->only(['web', 'horizon', 'scheduler', 'reverb'])->map(fn (array $service): bool => array_key_exists('environment', $service)))->each->toBeFalse()
        ->and($templateKeys->all())->toContain('LOG_CHANNEL', 'QUEUE_CONNECTION', 'BROADCAST_CONNECTION', 'REDIS_HOST', 'REVERB_HOST', 'REVERB_PORT', 'REVERB_SCHEME', 'DATABASIFY_VERSION', 'HTTP_PORT');
});
