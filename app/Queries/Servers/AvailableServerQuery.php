<?php

declare(strict_types=1);

namespace App\Queries\Servers;

use App\Models\Server;
use App\Queries\Query;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Query<Server>
 */
final class AvailableServerQuery extends Query
{
    private function __construct() {}

    public static function all(): self
    {
        return new self;
    }

    public function builder(): Builder
    {
        return Server::query()->available()->with(['network.serverProvider', 'serverProvider'])->byName();
    }
}
