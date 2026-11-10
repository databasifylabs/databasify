<?php

declare(strict_types=1);

namespace App\Services\Images;

use App\Contracts\Images\ImageRegistry;
use App\DTOs\Images\WrapperImage;
use Illuminate\Support\Facades\Cache;

final readonly class WrapperResolver
{
    private const int CACHE_SECONDS = 600;

    public function __construct(private ImageRegistry $registry) {}

    public function pinned(): WrapperImage
    {
        $digest = Cache::remember(self::cacheKey(), self::CACHE_SECONDS, fn (): string => $this->registry->digestOf(self::repository(), self::version()));

        return new WrapperImage(self::repository(), self::version(), $digest);
    }

    public static function cached(): ?WrapperImage
    {
        $digest = Cache::get(self::cacheKey());

        return is_string($digest) ? new WrapperImage(self::repository(), self::version(), $digest) : null;
    }

    public static function version(): string
    {
        return config()->string('databasify.wrapper.version');
    }

    public static function repository(): string
    {
        return config()->string('databasify.images.registry').'/'.config()->string('databasify.wrapper.repository');
    }

    private static function cacheKey(): string
    {
        return 'wrapper-digest.'.self::version();
    }
}
