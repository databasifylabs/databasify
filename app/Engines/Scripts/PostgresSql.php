<?php

declare(strict_types=1);

namespace App\Engines\Scripts;

use App\Contracts\Engines\SqlQuoting;

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
}
