<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Models\Project;
use Illuminate\Support\Facades\DB;

final readonly class DeleteProject
{
    public function handle(Project $project): void
    {
        $project->ensureDeletable();

        DB::transaction(fn () => $project->delete());
    }
}
