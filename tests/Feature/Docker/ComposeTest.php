<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('gives horizon a stop grace period of 30 minutes', function () {
    $compose = Yaml::parseFile(base_path('docker/compose.yaml'));

    expect($compose['services']['horizon']['stop_grace_period'])->toBe('30m');
});
