<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Contracts\Projects\Movable;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

final readonly class MoveToProject
{
    public function handle(Movable $resource, Project $target): void
    {
        $resource->ensureMovable();

        DB::transaction(fn () => collect($resource->movesAlongWith())
            ->push($resource)
            ->each(fn (Movable $movable) => $movable->moveTo($target)));
    }
}
