<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\DTOs\Projects\NewProject;
use App\Models\Project;

final readonly class CreateProject
{
    public function handle(NewProject $data): Project
    {
        return Project::query()->create(['name' => $data->name]);
    }
}
