<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Support;

final readonly class IndentedJson
{
    /**
     * @param  array<array-key, mixed>  $value
     */
    public static function of(array $value): string
    {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $twoSpaces = (string) preg_replace_callback('/^( +)/m', fn (array $match): string => str_repeat(' ', intdiv(strlen($match[1]), 2)), $json);

        return strtr($twoSpaces, ['<' => '\u003c', '>' => '\u003e', '&' => '\u0026']);
    }
}
