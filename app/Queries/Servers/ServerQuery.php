<?php

declare(strict_types=1);

namespace App\Queries\Servers;

use App\DTOs\Servers\ServerFilters;
use App\Models\Server;
use App\Queries\Query;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Query<Server>
 */
final class ServerQuery extends Query
{
    private function __construct(private readonly ServerFilters $filters) {}

    public static function all(): self
    {
        return new self(new ServerFilters);
    }

    public static function filteredBy(ServerFilters $filters): self
    {
        return new self($filters);
    }

    public function builder(): Builder
    {
        $query = Server::query()->with(['databaseNodes.database', 'network', 'serverProvider'])->latest('id');

        if ($this->filters->search !== '') {
            $query->matching($this->filters->search);
        }

        if ($this->filters->status !== null) {
            $query->where('status', $this->filters->status);
        }

        return $query;
    }
}
