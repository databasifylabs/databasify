<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Projects;

use App\Actions\Projects\RenameProject;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Http\Resources\Api\V1\ProjectResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_project')]
#[Description('Rename a project.')]
#[RequiresAccess(AccessArea::Projects, AccessLevel::Write)]
class UpdateProjectTool extends McpTool
{
    public function respond(UpdateProjectRequest $request, Project $project, RenameProject $rename): Response
    {
        $rename->handle($project, $request->projectName());

        return $this->json(new ProjectResource($project->refresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->uuid($schema, 'The project id.')->required(),
            'name' => $schema->string()->description('The new project name.')->required(),
        ];
    }
}
