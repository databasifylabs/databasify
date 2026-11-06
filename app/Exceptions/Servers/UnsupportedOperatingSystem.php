<?php

declare(strict_types=1);

namespace App\Exceptions\Servers;

use App\Enums\Servers\OperatingSystem;
use Illuminate\Support\Arr;
use RuntimeException;

final class UnsupportedOperatingSystem extends RuntimeException
{
    public static function running(string $os): self
    {
        $supported = Arr::join(array_map(fn (OperatingSystem $system): string => $system->label(), OperatingSystem::cases()), ', ', ' or ');

        return new self("Databasify needs {$supported}. This server runs {$os}.");
    }
}
