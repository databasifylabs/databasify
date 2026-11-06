<?php

declare(strict_types=1);

namespace App\Queries\Databases;

use App\DTOs\Databases\DatabaseFilters;
use App\Http\Resources\DatabaseListResource;
use App\Models\Database;
use App\Queries\Query;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Query<Database>
 */
final class DatabaseQuery extends Query
{
    private function __construct(private readonly DatabaseFilters $filters) {}

    public static function forList(DatabaseFilters $filters = new DatabaseFilters): self
    {
        return new self($filters);
    }

    public function builder(): Builder
    {
        $query = Database::query()->with(DatabaseListResource::RELATIONS)->latest('id');

        if ($this->filters->search !== '') {
            $query->matching($this->filters->search);
        }

        if ($this->filters->status !== null) {
            $query->where('status', $this->filters->status);
        }

        if ($this->filters->engine !== null) {
            $query->where('engine', $this->filters->engine);
        }

        return $query;
    }
}
