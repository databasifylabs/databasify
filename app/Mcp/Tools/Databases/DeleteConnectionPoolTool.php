<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Pools\DeleteConnectionPool;
use App\Actions\Databases\Pools\DeleteNodeConnectionPool;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Resources\Api\V1\DatabaseNodeResource;
use App\Http\Resources\Api\V1\DatabaseResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\ConnectionPool;
use App\Models\Database;
use App\Models\DatabaseNode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('delete_connection_pool')]
#[Description('Delete a connection pool of the database or of one read-only node. It runs in the background; poll get_database until the status settles.')]
#[IsDestructive]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Write)]
class DeleteConnectionPoolTool extends McpTool
{
    public function respond(Database $database, ?DatabaseNode $node, ConnectionPool $connectionPool, DeleteConnectionPool $delete, DeleteNodeConnectionPool $deleteOnNode): Response
    {
        if ($node === null) {
            $delete->handle($connectionPool);

            return $this->json(new DatabaseResource($database->refresh()));
        }

        $deleteOnNode->handle($node, $connectionPool);

        return $this->json(new DatabaseNodeResource($node->refresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
            'node' => $this->uuid($schema, 'A read-only node id, to use that node instead of the whole database.'),
            'connection_pool' => $this->uuid($schema, 'The pool id from list_connection_pools.')->required(),
        ];
    }
}
