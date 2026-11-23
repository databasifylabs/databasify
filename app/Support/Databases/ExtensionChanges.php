<?php

declare(strict_types=1);

namespace App\Support\Databases;

use App\DTOs\Databases\ExtensionChange;
use App\Models\Database;
use Illuminate\Support\Facades\Cache;

final readonly class ExtensionChanges
{
    private const int CACHE_SECONDS = 3600;

    public function start(Database $database, ExtensionChange $change): void
    {
        Cache::put($this->cacheKeyOf($database), $change->toArray(), self::CACHE_SECONDS);
    }

    public function of(Database $database): ?ExtensionChange
    {
        $cached = Cache::get($this->cacheKeyOf($database));

        return is_array($cached) ? ExtensionChange::fromArray($cached) : null;
    }

    public function end(Database $database): void
    {
        Cache::forget($this->cacheKeyOf($database));
    }

    private function cacheKeyOf(Database $database): string
    {
        return "database-extension-change.{$database->id}";
    }
}
