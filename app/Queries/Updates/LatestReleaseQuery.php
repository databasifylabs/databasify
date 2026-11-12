<?php

declare(strict_types=1);

namespace App\Queries\Updates;

use App\DTOs\Updates\CheckedRelease;
use App\DTOs\Updates\Release;
use App\Models\Setting;

final readonly class LatestReleaseQuery
{
    public function get(): ?CheckedRelease
    {
        $setting = Setting::query()->named('latest_release')->first();

        if ($setting?->value === null || $setting->updated_at === null) {
            return null;
        }

        return new CheckedRelease(Release::fromJson($setting->value), $setting->updated_at);
    }

    public function hasNewerRelease(): bool
    {
        return $this->get()?->release->isNewerThan(config()->string('app.version')) === true;
    }
}
