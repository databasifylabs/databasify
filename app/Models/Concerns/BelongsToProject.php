<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Project;
use App\Models\Scopes\ProjectScope;
use App\Support\Projects\ProjectContext;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToProject
{
    public static function bootBelongsToProject(): void
    {
        static::addGlobalScope(new ProjectScope);

        static::creating(function (self $model): void {
            $projectId = ProjectContext::current()->id();

            if ($model->getAttribute('project_id') !== null || $projectId === null) {
                return;
            }

            $model->setAttribute('project_id', $projectId);
        });
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function withoutProject(Builder $query): void
    {
        $query->withoutGlobalScope(ProjectScope::class);
    }
}
