<?php

declare(strict_types=1);

namespace App\Support\Ssh;

final readonly class Fingerprint
{
    public static function sha256(string $publicKey): string
    {
        return 'SHA256:'.rtrim(base64_encode(hash('sha256', self::blobOf($publicKey), true)), '=');
    }

    public static function md5(string $publicKey): string
    {
        return implode(':', str_split(md5(self::blobOf($publicKey)), 2));
    }

    private static function blobOf(string $publicKey): string
    {
        return base64_decode(explode(' ', trim($publicKey))[1] ?? '', true) ?: '';
    }
}
