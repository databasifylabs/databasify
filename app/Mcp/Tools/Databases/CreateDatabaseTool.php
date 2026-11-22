<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Lifecycle\CreateDatabase;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Requests\Databases\StoreDatabaseRequest;
use App\Http\Resources\Api\V1\DatabaseResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_database')]
#[Description('Create a database on one or more ready servers of a project: a primary and optional standbys on the same private network. It runs in the background; poll get_database until the status settles.')]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Write)]
class CreateDatabaseTool extends McpTool
{
    public function respond(StoreDatabaseRequest $request, CreateDatabase $create): Response
    {
        return $this->json(new DatabaseResource($create->handle($request->toDatabase())->refresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->uuid($schema, 'The project to create it in.')->required(),
            'name' => $schema->string()->description('Lowercase letters, digits and dashes; unique across databases.')->required(),
            'engine' => $schema->string()->enum(Database::engines())->description('The engine from list_database_engines.')->required(),
            'version_line' => $schema->string()->description('The version line, such as 18. Omit for the newest.'),
            'primary_server_id' => $this->uuid($schema, 'The ready server for the primary node.')->required(),
            'standby_server_ids' => $schema->array()->items($schema->string()->format('uuid'))->description('Ready servers for standby nodes.'),
        ];
    }
}
