<?php

declare(strict_types=1);

namespace App\Queries\Plugins;

use App\Models\Plugin;
use App\Queries\Query;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Query<Plugin>
 */
final class PluginQuery extends Query
{
    private function __construct() {}

    public static function all(): self
    {
        return new self;
    }

    public function builder(): Builder
    {
        return Plugin::query()->byName();
    }
}
