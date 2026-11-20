<?php

declare(strict_types=1);

namespace App\Support\Projects;

use App\Models\Project;

final class ProjectContext
{
    private ?Project $project = null;

    /** @var list<string>|null */
    private ?array $allowedIds = null;

    public static function current(): self
    {
        return resolve(self::class);
    }

    public function initialize(Project $project): void
    {
        $this->project = $project;
    }

    /**
     * @param  list<string>|null  $projectIds
     */
    public function limitTo(?array $projectIds): void
    {
        $this->allowedIds = $projectIds;
    }

    public function terminate(): void
    {
        $this->project = null;
        $this->allowedIds = null;
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
}
