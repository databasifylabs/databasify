<?php

declare(strict_types=1);

namespace App\Services\Images;

use App\Contracts\Images\ImageRegistry;
use Illuminate\Support\Facades\Cache;

final readonly class WrapperResolver
{
    public function __construct(private ImageRegistry $registry) {}

    public function pinned(): string
    {
        $version = self::version();

        return Cache::rememberForever("wrapper-image.{$version}", function () use ($version): string {
            $repository = self::repository();

            return "{$repository}:{$version}@{$this->registry->digestOf($repository, $version)}";
        });
    }

    public static function version(): string
    {
        return config()->string('databasify.wrapper.version');
    }

    public static function repository(): string
    {
        return config()->string('databasify.images.registry').'/'.config()->string('databasify.wrapper.repository');
    }
}
