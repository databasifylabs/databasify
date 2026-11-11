<?php

declare(strict_types=1);

namespace App\Actions\Plugins;

use App\DTOs\Plugins\PluginSource;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class ConfirmCommunityEngines
{
    /**
     * @param  list<string>  $engines
     */
    public function handle(array $engines, string $author, PluginSource $source): void
    {
        if ($engines === [] || $source->acceptsCommunityEngines) {
            return;
        }

        $named = Str::plural('Community engine', count($engines)).': '.implode(', ', $engines);

        throw ValidationException::withMessages(['community_engines' => "{$named}. This engine is built and supported by {$author}, not Databasify. Bugs or data loss in it are theirs to fix."]);
    }
}
