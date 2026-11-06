<?php

declare(strict_types=1);

namespace App\Queries\Dns;

use App\Models\DnsProvider;
use App\Queries\Query;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @extends Query<DnsProvider>
 */
final class DnsProviderQuery extends Query
{
    private function __construct() {}

    public static function withZones(): self
    {
        return new self;
    }

    public function builder(): Builder
    {
        return DnsProvider::query()->with(['zones' => fn (HasMany $zones) => $zones->withCount('databases')])->byName();
    }
}
