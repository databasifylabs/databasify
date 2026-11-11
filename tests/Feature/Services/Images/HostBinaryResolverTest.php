<?php

declare(strict_types=1);

use App\DTOs\Images\HostBinaryImage;
use App\Services\Images\HostBinaryResolver;
use Illuminate\Support\Facades\Cache;

it('reads the pinned host binary back from a cache store that serializes', function () {
    Cache::setDefaultDriver('database');
    resolve(HostBinaryResolver::class)->pinned();

    expect(resolve(HostBinaryResolver::class)->pinned())->toBeInstanceOf(HostBinaryImage::class);
});
