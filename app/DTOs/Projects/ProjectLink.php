<?php

declare(strict_types=1);

namespace App\DTOs\Projects;

use App\Models\Project;

final readonly class ProjectLink
{
    public function __construct(
        public string $id,
        public string $name,
    ) {}

    public static function fromModel(Project $project): self
    {
        return new self($project->id, $project->name);
    }

    /**
     * @return array{id: string, name: string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }
}
