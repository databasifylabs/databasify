<?php

declare(strict_types=1);

namespace App\Queries\Projects;

use App\Models\Project;
use App\Models\User;
use App\Support\Projects\ProjectContext;

final readonly class CurrentProjectQuery
{
    public function __construct(private ProjectContext $context) {}

    public function for(?User $user): ?Project
    {
        return $this->context->project() ?? $user->lastProject ?? Project::query()->byName()->first();
    }
}
