<?php

declare(strict_types=1);

namespace App\Services\Images;

use App\Contracts\Images\ImageRegistry;
use App\DTOs\Images\HostBinaryImage;
use App\Exceptions\Images\ImageUnavailable;
use Illuminate\Support\Facades\Cache;

final readonly class HostBinaryResolver
{
    private const int CACHE_SECONDS = 600;

    public function __construct(private ImageRegistry $registry) {}

    public function pinned(): HostBinaryImage
    {
        $repository = $this->repository();
        $version = $this->version();
        $digest = Cache::remember("host-binary-digest.{$version}", self::CACHE_SECONDS, fn (): string => $this->digestOf($repository, $version));

        return new HostBinaryImage($repository, $version, $digest);
    }

    private function digestOf(string $repository, string $version): string
    {
        try {
            return $this->registry->digestOf($repository, $version);
        } catch (ImageUnavailable $unavailable) {
            throw ImageUnavailable::hostBinary($this->name(), $version, $unavailable);
        }
    }

    private function repository(): string
    {
        return config()->string('databasify.images.registry').'/'.config()->string('databasify.sdk.host_repository');
    }

    private function version(): string
    {
        return config()->string('databasify.sdk.host');
    }

    private function name(): string
    {
        return config()->string('databasify.sdk.host_repository');
    }
}
