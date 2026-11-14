<?php

declare(strict_types=1);

namespace App\Support\Engines;

use App\DTOs\Databases\EngineImage;
use App\Exceptions\Images\ImageUnavailable;
use App\Support\Plugins\VersionConstraint;
use Illuminate\Support\Str;

final readonly class SdkRange
{
    public static function allows(?string $sdk, ?string $constraint = null): bool
    {
        if ($sdk === null) {
            return false;
        }

        return VersionConstraint::allows($constraint ?? self::constraint(), Str::of($sdk)->ltrim('v')->before('+')->value());
    }

    public static function supports(EngineImage $image, ?string $constraint = null): bool
    {
        return self::allows($image->sdk, $constraint);
    }

    public static function supported(string $engine, EngineImage $image): EngineImage
    {
        if (self::supports($image)) {
            return $image;
        }

        throw ImageUnavailable::unsupportedSdk($engine, $image, $image->sdk);
    }

    public static function refusalOf(string $image, ?string $sdk): string
    {
        $built = $sdk ?? 'unknown';

        return "{$image} is built on SDK {$built}; this Databasify supports ".self::constraint().'.';
    }

    public static function refusalFor(string $engineLabel, EngineImage $image): string
    {
        return self::refusalOf("{$engineLabel} {$image->release}", $image->sdk);
    }

    public static function constraint(): string
    {
        return config()->string('databasify.require.engine-sdk');
    }
}
