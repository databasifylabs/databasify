<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Projects;

use App\Actions\Projects\MoveToProject;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Requests\Api\V1\MoveToProjectRequest;
use App\Http\Resources\Api\V1\MovedResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('move_to_project')]
#[Description('Move a server, network, provider or database (with the servers behind its nodes) from one project to another.')]
#[IsDestructive]
#[RequiresAccess(AccessArea::Projects, AccessLevel::Write)]
class MoveToProjectTool extends McpTool
{
    public function respond(MoveToProjectRequest $request, MoveToProject $move): Response
    {
        $resource = $request->resource();
        $move->handle($resource, $request->target());

        return $this->json(new MovedResource($resource instanceof Model ? $resource->refresh() : $resource));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->uuid($schema, 'The project the resource is in now.')->required(),
            'type' => $schema->string()->description('What to move: server, database, network, server-provider, dns-provider or storage-provider.')->required(),
            'id' => $this->uuid($schema, 'The id of the resource to move.')->required(),
            'project_id' => $this->uuid($schema, 'The project to move it to.')->required(),
        ];
    }
}
