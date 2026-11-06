<?php

declare(strict_types=1);

namespace App\Queries\Storage;

use App\Models\StorageProvider;
use App\Queries\Query;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Query<StorageProvider>
 */
final class StorageProviderQuery extends Query
{
    private function __construct(private readonly bool $withRecoveries) {}

    public static function all(): self
    {
        return new self(withRecoveries: false);
    }

    public static function withRecoveries(): self
    {
        return new self(withRecoveries: true);
    }

    public function builder(): Builder
    {
        $query = StorageProvider::query()->byName();

        if ($this->withRecoveries) {
            $query->with('recoveries.database');
        }

        return $query;
    }
}
