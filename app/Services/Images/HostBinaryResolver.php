<?php

declare(strict_types=1);

namespace App\Services\Images;

use App\Contracts\Images\ImageRegistry;
use App\DTOs\Images\HostBinaryImage;
use App\Exceptions\Images\ImageUnavailable;
use App\Support\Engines\SdkRange;
use App\Support\Images\ImageCache;
use Closure;

final readonly class HostBinaryResolver
{
    private const string RELEASE = '/^\d+\.\d+\.\d+$/';

    public function __construct(
        private ImageRegistry $registry,
        private ImageCache $cache,
    ) {}

    public function pinned(): HostBinaryImage
    {
        $constraint = SdkRange::constraint();

        return $this->cache->hostBinary($constraint, fn (): HostBinaryImage => $this->newestIn($constraint));
    }

    private function newestIn(string $constraint): HostBinaryImage
    {
        $repository = config()->string('databasify.registry').'/engine-sdk';
        $version = $this->newestReleaseIn($this->fromRegistry(fn (): array => $this->registry->tagsOf($repository), $constraint), $constraint);

        return new HostBinaryImage($repository, $version, $this->fromRegistry(fn (): string => $this->registry->digestOf($repository, $version), $constraint));
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $call
     * @return TResult
     */
    private function fromRegistry(Closure $call, string $constraint): mixed
    {
        try {
            return $call();
        } catch (ImageUnavailable $unavailable) {
            throw ImageUnavailable::hostBinary($constraint, $unavailable);
        }
    }

    /**
     * @param  list<string>  $tags
     */
    private function newestReleaseIn(array $tags, string $constraint): string
    {
        $release = collect($tags)
            ->filter(fn (string $tag): bool => preg_match(self::RELEASE, $tag) === 1 && SdkRange::allows($tag, $constraint))
            ->sort(fn (string $left, string $right): int => version_compare($left, $right))
            ->last();

        return is_string($release) ? $release : throw ImageUnavailable::noHostRelease($constraint);
    }
}
