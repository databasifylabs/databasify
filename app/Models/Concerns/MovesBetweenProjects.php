<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Contracts\Projects\Movable;
use App\Models\Project;

trait MovesBetweenProjects
{
    public function ensureMovable(): void {}

    /**
     * @return iterable<int, Movable>
     */
    public function movesAlongWith(): iterable
    {
        return [];
    }

    public function moveTo(Project $project): void
    {
        $this->update(['project_id' => $project->id]);
    }
}
