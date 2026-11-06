<?php

declare(strict_types=1);

namespace App\Exceptions\Providers;

use Illuminate\Http\RedirectResponse;
use RuntimeException;

final class UnknownProvider extends RuntimeException
{
    public static function named(string $id): self
    {
        return new self("No provider is registered as \"{$id}\". Install or enable the plugin that adds it.");
    }

    public function render(): RedirectResponse
    {
        return back()->withErrors(['provider' => $this->getMessage()]);
    }
}
