<?php

declare(strict_types=1);

namespace App\Actions\Plugins;

use App\DTOs\Plugins\PluginSource;
use Illuminate\Validation\ValidationException;

final readonly class ConfirmCommunityEngines
{
    public function handle(?string $engine, string $author, PluginSource $source): void
    {
        if ($engine === null || $source->acceptsCommunityEngines || $source->isOfficial()) {
            return;
        }

        throw ValidationException::withMessages(['community_engines' => "Community engine: {$engine}. This engine is built and supported by {$author}, not Databasify. Bugs or data loss in it are theirs to fix."]);
    }
}
