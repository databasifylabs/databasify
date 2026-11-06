<?php

declare(strict_types=1);

use Illuminate\Support\Str;

if (! function_exists('new_password')) {
    function new_password(): string
    {
        return Str::password(32, symbols: false);
    }
}
