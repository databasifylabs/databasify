<?php

declare(strict_types=1);

namespace App\Queries\Providers;

use App\Models\ServerProvider;
use App\Queries\Query;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Query<ServerProvider>
 */
final class ServerProviderQuery extends Query
{
    private function __construct(private readonly bool $withUsage) {}

    public static function all(): self
    {
        return new self(withUsage: false);
    }

    public static function withUsage(): self
    {
        return new self(withUsage: true);
    }

    public function builder(): Builder
    {
        $query = ServerProvider::query()->byName();

        if ($this->withUsage) {
            $query->withCount(['servers', 'networks']);
        }

        return $query;
    }
}
