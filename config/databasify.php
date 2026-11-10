<?php

declare(strict_types=1);

return [
    'repository' => env('DATABASIFY_REPOSITORY', 'databasifylabs/databasify'),

    'images' => [
        'registry' => env('DATABASIFY_IMAGE_REGISTRY', 'ghcr.io/databasifylabs'),
        'repository' => env('DATABASIFY_IMAGE_REPOSITORY', 'databasify-{engine}'),
        'wrapper' => env('DATABASIFY_WRAPPER_REPOSITORY', 'databasify-wrapper'),
        'minimum_wrapper' => '0.1.0',
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
