<?php

declare(strict_types=1);

namespace App\Services\Images;

use App\Contracts\Images\ImageRegistry;
use Illuminate\Support\Facades\Cache;

final readonly class WrapperResolver
{
    private const int CACHE_SECONDS = 240;

    public function __construct(private ImageRegistry $registry) {}

    public function newestFor(int $api): string
    {
        return Cache::remember("wrapper-image.{$api}", self::CACHE_SECONDS, function () use ($api): string {
            $repository = self::repository();
            $tag = "api-{$api}";

            return "{$repository}:{$tag}@{$this->registry->digestOf($repository, $tag)}";
        });
    }

    public static function repository(): string
    {
        return config()->string('databasify.images.registry').'/'.config()->string('databasify.images.wrapper');
    }
}
