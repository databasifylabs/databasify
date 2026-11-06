<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\DatabaseProviderServiceProvider;
use App\Providers\DnsProviderServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\PluginServiceProvider;
use App\Providers\ServerProviderServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
    DatabaseProviderServiceProvider::class,
    DnsProviderServiceProvider::class,
    ServerProviderServiceProvider::class,
    PluginServiceProvider::class,
];
