<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Projects;

use App\Actions\Projects\DeleteProject;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Requests\Api\V1\DeleteProjectRequest;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('delete_project')]
#[Description('Delete an empty project. Refused while it still holds servers, databases or providers, and for keys limited to some projects.')]
#[IsDestructive]
#[RequiresAccess(AccessArea::Projects, AccessLevel::Write)]
class DeleteProjectTool extends McpTool
{
    public function respond(DeleteProjectRequest $request, Project $project, DeleteProject $delete): Response
    {
        $delete->handle($project);

        return Response::json(['deleted' => $project->id]);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['project' => $this->uuid($schema, 'The project id.')->required()];
    }
}
