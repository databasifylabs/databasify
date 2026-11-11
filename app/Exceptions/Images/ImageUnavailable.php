<?php

declare(strict_types=1);

namespace App\Exceptions\Images;

use App\Support\Engines\SdkRange;
use RuntimeException;
use Throwable;

final class ImageUnavailable extends RuntimeException
{
    public function __construct(string $message, public readonly bool $refused = false, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    public static function unreachable(string $repository): self
    {
        return new self("The image registry for {$repository} could not be reached.");
    }

    public static function answered(string $repository, int $status): self
    {
        return new self("The image registry answered {$status} for {$repository}.");
    }

    public static function noReleaseOf(string $label, string $repository): self
    {
        return new self("No {$label} image is published in {$repository}.");
    }

    public static function hostBinary(string $name, string $version, self $previous): self
    {
        return new self("The host binary {$name}:{$version} could not be resolved: {$previous->getMessage()}", previous: $previous);
    }

    public static function unsupportedSdk(string $image, ?string $sdk): self
    {
        return new self(SdkRange::refusalOf($image, $sdk), refused: true);
    }
}
