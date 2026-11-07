<?php

declare(strict_types=1);

namespace App\Contracts\Projects;

use App\Models\Project;

interface Movable
{
    public function ensureMovable(): void;

    /**
     * @return iterable<int, Movable>
     */
    public function movesAlongWith(): iterable;

    public function moveTo(Project $project): void;
}
