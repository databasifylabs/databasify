<?php

declare(strict_types=1);

arch('shared code reaches engines only through the driver contracts')
    ->expect(['App\Actions', 'App\Http', 'App\Mcp', 'App\Jobs', 'App\DTOs', 'App\Support', 'App\Enums'])
    ->not->toUse('App\Engines\Drivers');

arch('only the area cache classes in Support touch the cache')
    ->expect('Illuminate\Support\Facades\Cache')
    ->toOnlyBeUsedIn('App\Support');
