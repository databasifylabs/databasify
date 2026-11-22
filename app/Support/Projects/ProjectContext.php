<?php

declare(strict_types=1);

namespace App\Support\Projects;

use App\Models\Project;
use Illuminate\Database\Eloquent\Model;

final class ProjectContext
{
    private ?Project $project = null;

    /** @var list<string>|null */
    private ?array $allowedIds = null;

    public static function current(): self
    {
        return resolve(self::class);
    }

    public function initialize(Model|string|null $source): ?Project
    {
        $project = $this->projectFrom($source);

        if ($project === null || ! $this->allows($project->id)) {
            return null;
        }

        return $this->project = $project;
    }

    /**
     * @param  list<string>|null  $projectIds
     */
    public function limitTo(?array $projectIds): void
    {
        $this->allowedIds = $projectIds;
    }

    public function terminate(bool $keepAllowedProjects = false): void
    {
        $this->project = null;

        if (! $keepAllowedProjects) {
            $this->allowedIds = null;
        }
    }

    public function project(): ?Project
    {
        return $this->project;
    }

    public function id(): ?string
    {
        return $this->project?->id;
    }

    /**
     * @return list<string>|null
     */
    public function allowedIds(): ?array
    {
        return $this->allowedIds;
    }

    public function isLimited(): bool
    {
        return $this->allowedIds !== null;
    }

    public function allows(string $projectId): bool
    {
        return $this->allowedIds === null || in_array($projectId, $this->allowedIds, true);
    }

    private function projectFrom(Model|string|null $source): ?Project
    {
        if ($source === null || $source instanceof Project) {
            return $source;
        }

        $projectId = is_string($source) ? $source : ($source->getAttributes()['project_id'] ?? null);

        return is_string($projectId) ? Project::query()->find($projectId) : null;
    }
}
