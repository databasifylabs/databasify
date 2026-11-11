<?php

declare(strict_types=1);

namespace App\Services\Images;

use App\Contracts\Images\ImageRegistry;
use App\DTOs\Databases\EngineImage;
use App\Exceptions\Images\ImageUnavailable;
use App\Models\Database;
use App\Support\Engines\SdkRange;

final readonly class ImageResolver
{
    public function __construct(private ImageRegistry $registry) {}

    public function newest(Database $database, string $line): EngineImage
    {
        $engine = $database->engine();
        $repository = $engine->imageRepository;
        $release = $this->newestReleaseIn($this->registry->tagsOf($repository), $line)
            ?? throw ImageUnavailable::noReleaseOf("{$engine->label} {$line}", $repository);
        $digest = $this->registry->digestOf($repository, $release);
        $sdk = $this->registry->labelsOf($repository, $digest)->sdk;

        if (! SdkRange::allows($sdk)) {
            throw ImageUnavailable::unsupportedSdk("{$engine->label} {$release}", $sdk);
        }

        return new EngineImage($repository, $digest, $release, $sdk);
    }

    /**
     * @param  list<string>  $tags
     */
    private function newestReleaseIn(array $tags, string $line): ?string
    {
        $pattern = '/^'.preg_quote($line, '/').'(\.\d+)+$/';

        return collect($tags)
            ->filter(fn (string $tag): bool => preg_match($pattern, $tag) === 1)
            ->sort(fn (string $a, string $b): int => version_compare($a, $b))
            ->last();
    }
}
