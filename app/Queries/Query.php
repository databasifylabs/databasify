<?php

declare(strict_types=1);

namespace App\Queries;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * @template TModel of Model
 */
abstract class Query
{
    public const int PER_PAGE = 25;

    /**
     * @return Builder<TModel>
     */
    abstract public function builder(): Builder;

    /**
     * @return Collection<int, TModel>
     */
    public function get(): Collection
    {
        return $this->builder()->get();
    }

    /**
     * @return Collection<int, TModel>
     */
    public function take(int $count): Collection
    {
        return $this->builder()->limit($count)->get();
    }

    /**
     * @return TModel|null
     */
    public function first(): ?Model
    {
        return $this->builder()->first();
    }

    public function count(): int
    {
        return $this->builder()->count();
    }

    public function exists(): bool
    {
        return $this->builder()->exists();
    }

    /**
     * @return CursorPaginator<int, TModel>
     */
    public function cursorPaginate(?int $perPage = null): CursorPaginator
    {
        return $this->builder()->cursorPaginate($perPage ?? static::PER_PAGE)->withQueryString();
    }
}
