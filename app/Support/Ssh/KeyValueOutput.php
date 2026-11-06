<?php

declare(strict_types=1);

namespace App\Support\Ssh;

final readonly class KeyValueOutput
{
    /**
     * @return array<string, string>
     */
    public static function parse(string $output): array
    {
        return collect(explode("\n", trim($output)))
            ->filter(fn (string $line): bool => str_contains($line, '='))
            ->mapWithKeys(function (string $line): array {
                [$key, $value] = explode('=', $line, 2);

                return [trim($key) => trim($value)];
            })
            ->all();
    }
}
