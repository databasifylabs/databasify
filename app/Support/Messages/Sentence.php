<?php

declare(strict_types=1);

namespace App\Support\Messages;

use Illuminate\Support\Str;

final class Sentence
{
    public static function of(string $text): string
    {
        $text = rtrim($text);

        return Str::endsWith($text, ['.', '!', '?']) ? $text : "{$text}.";
    }
}
