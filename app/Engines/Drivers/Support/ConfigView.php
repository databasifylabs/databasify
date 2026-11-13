<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Support;

use Illuminate\Contracts\View\Factory;
use LogicException;

final readonly class ConfigView
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function render(string $view, array $data): string
    {
        $views = resolve(Factory::class);

        if (! $views->exists($view)) {
            throw new LogicException("There is no config file view {$view}.");
        }

        return $views->make($view, $data)->render();
    }
}
