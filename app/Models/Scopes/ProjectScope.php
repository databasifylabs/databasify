<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\Projects\ProjectContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * @implements Scope<Model>
 */
final class ProjectScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $projectId = ProjectContext::current()->id();

        if ($projectId === null) {
            return;
        }

        $builder->where($model->qualifyColumn('project_id'), $projectId);
    }
}
