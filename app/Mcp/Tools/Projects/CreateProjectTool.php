<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Projects;

use App\Actions\Projects\CreateProject;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Requests\Api\V1\StoreProjectRequest;
use App\Http\Resources\Api\V1\ProjectResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_project')]
#[Description('Create a project. Keys limited to some projects cannot create projects.')]
#[RequiresAccess(AccessArea::Projects, AccessLevel::Write)]
class CreateProjectTool extends McpTool
{
    public function respond(StoreProjectRequest $request, CreateProject $create): Response
    {
        return $this->json(new ProjectResource($create->handle($request->toProject())));
    }

    public function schema(JsonSchema $schema): array
    {
        return ['name' => $schema->string()->description('The project name.')->required()];
    }
}
