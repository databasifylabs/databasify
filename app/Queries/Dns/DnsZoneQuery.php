<?php

declare(strict_types=1);

namespace App\Queries\Dns;

use App\Models\DnsZone;
use App\Queries\Query;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Query<DnsZone>
 */
final class DnsZoneQuery extends Query
{
    private function __construct() {}

    public static function all(): self
    {
        return new self;
    }

    public function builder(): Builder
    {
        return DnsZone::query()->with('provider')->byName();
    }
}
