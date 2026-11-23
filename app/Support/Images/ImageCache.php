<?php

declare(strict_types=1);

namespace App\Support\Images;

use App\DTOs\Images\HostBinaryImage;
use Closure;
use Illuminate\Support\Facades\Cache;

final readonly class ImageCache
{
    private const int HOST_BINARY_SECONDS = 600;

    /**
     * @param  Closure(): HostBinaryImage  $resolve
     */
    public function hostBinary(string $constraint, Closure $resolve): HostBinaryImage
    {
        return Cache::remember("host-binary.{$constraint}", self::HOST_BINARY_SECONDS, $resolve);
    }

    /**
     * @param  Closure(): array<string, string>  $read
     * @return array<string, string>
     */
    public function extensionsOf(string $digest, Closure $read): array
    {
        return Cache::rememberForever("image-extensions.{$digest}", $read);
    }

    /**
     * @param  Closure(): string  $read
     */
    public function operatingSystemOf(string $digest, Closure $read): string
    {
        return Cache::rememberForever("image-os.{$digest}", $read);
    }
}
