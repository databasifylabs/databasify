<?php

declare(strict_types=1);

namespace App\Queries\Networks;

use App\Models\Network;
use App\Queries\Query;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Query<Network>
 */
final class NetworkQuery extends Query
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
        $query = Network::query()->with('serverProvider')->byName();

        if ($this->withUsage) {
            $query->withCount(['servers', 'connectedServers']);
        }

        return $query;
    }
}
