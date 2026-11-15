<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\WithCachedConfig;
use Illuminate\Foundation\Testing\WithCachedRoutes;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class, WithCachedConfig::class, WithCachedRoutes::class)
    ->beforeEach(function (): void {
        Http::preventStrayRequests();
        Sleep::fake(syncWithCarbon: true);
    })
    ->in('Feature');
