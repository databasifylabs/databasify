<?php

declare(strict_types=1);

return [
    'images' => [
        'registry' => env('DATABASIFY_IMAGE_REGISTRY', 'ghcr.io/databasifylabs'),
        'repository' => env('DATABASIFY_IMAGE_REPOSITORY', 'databasify-{engine}'),
    ],

    'sdk' => [
        'host' => '0.1.1',
        'host_repository' => 'databasify-host',
        'range' => '^0.1',
    ],

    'docker' => [
        'version' => '29.9.2',
        'containerd' => '2.4.2',
    ],

    'plugins' => [
        'path' => env('DATABASIFY_PLUGINS_PATH', storage_path('plugins')),
    ],

    'acme' => [
        'mode' => env('ACME_MODE', 'live'),
        'propagation_seconds' => (int) env('ACME_PROPAGATION_SECONDS', 30),
    ],
];
