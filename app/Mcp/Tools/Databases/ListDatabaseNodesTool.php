<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Resources\Api\V1\DatabaseNodeResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_database_nodes')]
#[Description('List the nodes of a database with their role, server, status and engine version.')]
#[IsReadOnly]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Read)]
class ListDatabaseNodesTool extends McpTool
{
    public function respond(Database $database): Response
    {
        return $this->json(DatabaseNodeResource::collection($database->nodes));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
        ];
    }
}
