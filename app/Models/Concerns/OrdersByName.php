<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

trait OrdersByName
{
    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function byName(Builder $query): void
    {
        $query->orderBy('name');
    }
}
