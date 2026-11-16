<?php

declare(strict_types=1);

namespace App\Engines\Scripts;

use App\Engines\Contracts\SqlQuoting;

final readonly class PostgresSql implements SqlQuoting
{
    public function string(string $value): string
    {
        return "'".str_replace("'", "''", Shell::withoutNul($value))."'";
    }

    public function name(string $value): string
    {
        return '"'.str_replace('"', '""', Shell::withoutNul($value)).'"';
    }

    public function connect(string $database): string
    {
        return "\\connect '".str_replace(['\\', "'"], ['\\\\', "''"], Shell::withoutNul($database))."'";
    }
}
