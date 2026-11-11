<?php

declare(strict_types=1);

namespace App\Actions\Databases\Upgrades;

use App\Models\Database;
use Illuminate\Support\LazyCollection;

final readonly class FindUnsupportedSdkDatabases
{
    /**
     * @return LazyCollection<int, Database>
     */
    public function handle(?string $constraint = null): LazyCollection
    {
        return Database::query()->orderBy('name')->lazy()
            ->reject(fn (Database $database): bool => $database->runsSupportedSdk($constraint));
    }
}
