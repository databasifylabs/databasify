<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Models\Project;

final readonly class RenameProject
{
    public function handle(Project $project, string $name): void
    {
        $project->update(['name' => $name]);
    }
}
