<?php

declare(strict_types=1);

namespace App\Support\Ssh;

final readonly class CertificateOutput
{
    /**
     * @return array<string, string>
     */
    public static function parse(string $output): array
    {
        return collect(explode("\n", $output))
            ->filter(fn (string $line): bool => str_starts_with($line, 'CERT '))
            ->mapWithKeys(function (string $line): array {
                [, $name, $content] = array_pad(explode(' ', trim($line), 3), 3, '');

                $decoded = base64_decode($content, true);

                return [$name => $decoded === false ? '' : $decoded];
            })
            ->all();
    }
}
