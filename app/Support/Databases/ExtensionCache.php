<?php

declare(strict_types=1);

namespace App\Support\Databases;

use App\Engines\Data\InstalledExtension;
use App\Models\Database;
use Closure;
use Illuminate\Support\Facades\Cache;

final readonly class ExtensionCache
{
    private const int INSTALLED_SECONDS = 60;

    private const int UPDATE_COUNT_SECONDS = 300;

    /**
     * @param  Closure(): list<InstalledExtension>  $read
     * @return list<InstalledExtension>
     */
    public function installed(Database $database, string $schema, Closure $read): array
    {
        return Cache::remember($this->installedKeyOf($database, $schema), self::INSTALLED_SECONDS, $read);
    }

    /**
     * @param  Closure(): int  $count
     */
    public function updateCount(Database $database, Closure $count): int
    {
        Cache::remember($this->updateCountKeyOf($database), self::UPDATE_COUNT_SECONDS, $count);

        return Cache::integer($this->updateCountKeyOf($database), 0);
    }

    public function forget(Database $database, string $schema): void
    {
        Cache::forget($this->installedKeyOf($database, $schema));
        Cache::forget($this->updateCountKeyOf($database));
    }

    private function installedKeyOf(Database $database, string $schema): string
    {
        return "database-extensions.{$database->id}.{$schema}";
    }

    private function updateCountKeyOf(Database $database): string
    {
        return "database-extension-updates.{$database->id}";
    }
}
