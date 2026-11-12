<?php

declare(strict_types=1);

return [
    'registry' => env('DATABASIFY_REGISTRY', 'ghcr.io/databasifylabs'),

    'require' => [
        'engine-sdk' => '^0.2',
    ],

    'php_engines' => array_values(array_filter(explode(',', (string) env('DATABASIFY_PHP_ENGINES', '')))),

    'docker' => [
        'version' => '29.9.2',
        'containerd' => '2.4.2',
    ],

    'plugins' => [
        'path' => env('DATABASIFY_PLUGINS_PATH', storage_path('plugins')),
    ],

    'updates' => [
        'source' => env('DATABASIFY_UPDATE_SOURCE', 'https://get.databasify.io/version.json'),
        'path' => env('DATABASIFY_UPDATER_PATH', '/app/updater'),
        'pinned' => env('DATABASIFY_VERSION'),
    ],

    'acme' => [
        'mode' => env('ACME_MODE', 'live'),
        'propagation_seconds' => (int) env('ACME_PROPAGATION_SECONDS', 30),
    ],
];
