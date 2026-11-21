<?php

declare(strict_types=1);

namespace App\Queries\Projects;

use App\Models\Project;
use App\Queries\Query;
use App\Support\Projects\ProjectContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Query<Project>
 */
final class ProjectQuery extends Query
{
    private function __construct(private readonly bool $withUsage) {}

    public static function withUsage(): self
    {
        return new self(withUsage: true);
    }

    public static function links(): self
    {
        return new self(withUsage: false);
    }

    public function builder(): Builder
    {
        $allowedIds = ProjectContext::current()->allowedIds();
        $query = Project::query()->byName()->when($allowedIds !== null, fn (Builder $query): Builder => $query->whereKey($allowedIds));

        if ($this->withUsage) {
            return $query->withCount(['servers', 'databases', 'networks', 'serverProviders', 'dnsProviders', 'storageProviders']);
        }

        return $query->select(['id', 'name']);
    }
}
