<?php

declare(strict_types=1);

namespace App\Exceptions\Images;

use App\DTOs\Databases\EngineImage;
use App\Support\Engines\SdkRange;
use RuntimeException;
use Throwable;

final class ImageUnavailable extends RuntimeException
{
    public function __construct(string $message, public readonly ?EngineImage $refused = null, ?Throwable $previous = null)
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

    public static function hostBinary(string $constraint, self $previous): self
    {
        return new self("The host binary engine-sdk {$constraint} could not be resolved: {$previous->getMessage()}", previous: $previous);
    }

    public static function noHostRelease(string $constraint): self
    {
        return new self("No engine-sdk release satisfies {$constraint}.");
    }

    public static function unsupportedSdk(string $engine, EngineImage $image, ?string $sdk): self
    {
        return new self(SdkRange::refusalOf("{$engine} {$image->release}", $sdk), refused: $image);
    }
}
