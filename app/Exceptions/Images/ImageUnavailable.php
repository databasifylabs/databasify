<?php

declare(strict_types=1);

namespace App\Exceptions\Images;

use RuntimeException;

final class ImageUnavailable extends RuntimeException
{
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
}
