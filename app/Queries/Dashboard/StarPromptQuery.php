<?php

declare(strict_types=1);

namespace App\Queries\Dashboard;

use App\Models\Database;
use App\Models\Setting;

final readonly class StarPromptQuery
{
    private function __construct() {}

    public static function current(): self
    {
        return new self;
    }

    public function shows(): bool
    {
        if (Setting::query()->named('star_prompt_dismissed_at')->exists()) {
            return false;
        }

        return Database::query()->withoutProject()->ready()->exists();
    }
}
