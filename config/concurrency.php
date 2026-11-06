<?php

declare(strict_types=1);

return [
    'default' => env('CONCURRENCY_DRIVER', PHP_SAPI === 'cli' ? 'fork' : 'process'),
];
