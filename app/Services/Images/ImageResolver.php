<?php

declare(strict_types=1);

namespace App\Services\Images;

use App\Contracts\Images\ImageRegistry;
use App\DTOs\Databases\EngineImage;
use App\DTOs\Images\LineImages;
use App\Exceptions\Images\ImageUnavailable;
use App\Models\Database;
use App\Support\Engines\SdkRange;
use Illuminate\Support\LazyCollection;

final readonly class ImageResolver
{
    public function __construct(private ImageRegistry $registry) {}

    public function newest(Database $database, string $line): EngineImage
    {
        $images = $this->imagesOf($database, $line);

        return $images->supported ?? throw ImageUnavailable::unsupportedSdk($database->engine()->label, $images->newest, $images->newest->sdk);
    }

    public function imagesOf(Database $database, string $line): LineImages
    {
        $engine = $database->engine();
        $images = $this->releasesIn($engine->imageRepository, $line)->remember();
        $newest = $images->first() ?? throw ImageUnavailable::noReleaseOf("{$engine->label} {$line}", $engine->imageRepository);

        return new LineImages($newest, $images->first(fn (EngineImage $image): bool => SdkRange::allows($image->sdk)));
    }

    /**
     * @return LazyCollection<int, EngineImage>
     */
    private function releasesIn(string $repository, string $line): LazyCollection
    {
        $pattern = '/^'.preg_quote($line, '/').'(\.\d+)+$/';

        return LazyCollection::make($this->registry->tagsOf($repository))
            ->filter(fn (string $tag): bool => preg_match($pattern, $tag) === 1)
            ->sort(fn (string $a, string $b): int => version_compare($b, $a))
            ->values()
            ->map(fn (string $release): EngineImage => $this->imageOf($repository, $release));
    }

    private function imageOf(string $repository, string $release): EngineImage
    {
        $digest = $this->registry->digestOf($repository, $release);

        return new EngineImage($repository, $digest, $release, $this->registry->labelsOf($repository, $digest)->sdk);
    }
}
