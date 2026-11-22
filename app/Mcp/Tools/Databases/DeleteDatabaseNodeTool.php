<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Nodes\RemoveDatabaseNode;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Resources\Api\V1\DatabaseResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use App\Models\DatabaseNode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('delete_database_node')]
#[Description('Remove a node from a database. The last member cannot be removed. It runs in the background; poll get_database until the status settles.')]
#[IsDestructive]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Write)]
class DeleteDatabaseNodeTool extends McpTool
{
    public function respond(Request $request, Database $database, DatabaseNode $node, RemoveDatabaseNode $remove): Response
    {
        $remove->handle($node, $request->boolean('force'));

        return $this->json(new DatabaseResource($database->refresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
            'node' => $this->uuid($schema, 'The node id from list_database_nodes.')->required(),
            'force' => $schema->boolean()->description('Remove even if the server cannot be reached.'),
        ];
    }
}
