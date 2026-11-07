<?php

declare(strict_types=1);

namespace App\Actions\Repository;

use App\Models\Setting;

final readonly class DismissStarPrompt
{
    public function handle(): void
    {
        Setting::query()->updateOrCreate(['name' => 'star_prompt_dismissed_at'], ['value' => now()->toIso8601String()]);
    }
}
