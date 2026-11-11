<?php

declare(strict_types=1);

return [
    'registry' => env('DATABASIFY_REGISTRY', 'ghcr.io/databasifylabs'),

    'require' => [
        'engine-sdk' => '^0.2',
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
