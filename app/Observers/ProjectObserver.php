<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Project;

final readonly class ProjectObserver
{
    public function deleted(Project $project): void
    {
        $project->lastVisitors()->update(['last_project_id' => null]);
    }
}
