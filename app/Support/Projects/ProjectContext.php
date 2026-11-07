<?php

declare(strict_types=1);

namespace App\Support\Projects;

use App\Models\Project;

final class ProjectContext
{
    private ?Project $project = null;

    public static function current(): self
    {
        return resolve(self::class);
    }

    public function initialize(Project $project): void
    {
        $this->project = $project;
    }

    public function terminate(): void
    {
        $this->project = null;
    }

    public function isInitialized(): bool
    {
        return $this->project !== null;
    }

    public function project(): ?Project
    {
        return $this->project;
    }

    public function id(): ?string
    {
        return $this->project?->id;
    }
}
