<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;

trait MatchesText
{
    /**
     * @param  Builder<static>  $query
     * @param  literal-string  $column
     */
    #[Scope]
    protected function orWhereContains(Builder $query, string $column, string $term): void
    {
        $operator = $query->getGrammar() instanceof PostgresGrammar ? 'ilike' : 'like';
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';

        $query->orWhereRaw("{$column} {$operator} ? escape '!'", [$pattern]);
    }
}
