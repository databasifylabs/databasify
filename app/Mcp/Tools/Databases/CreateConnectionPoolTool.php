<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Pools\CreateConnectionPool;
use App\Actions\Databases\Pools\CreateNodeConnectionPool;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Enums\Databases\PoolMode;
use App\Http\Requests\Databases\StoreConnectionPoolRequest;
use App\Http\Resources\Api\V1\DatabaseNodeResource;
use App\Http\Resources\Api\V1\DatabaseResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use App\Models\DatabaseNode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_connection_pool')]
#[Description('Create a connection pool for a schema, on the database or on one read-only node. It runs in the background; poll get_database until the status settles.')]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Write)]
class CreateConnectionPoolTool extends McpTool
{
    public function respond(StoreConnectionPoolRequest $request, Database $database, ?DatabaseNode $node, CreateConnectionPool $create, CreateNodeConnectionPool $createOnNode): Response
    {
        if ($node === null) {
            $create->handle($database, $request->toPool());

            return $this->json(new DatabaseResource($database->refresh()));
        }

        $createOnNode->handle($node, $request->toPool());

        return $this->json(new DatabaseNodeResource($node->refresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
            'node' => $this->uuid($schema, 'A read-only node id, to use that node instead of the whole database.'),
            'name' => $schema->string()->description('Lowercase letters, digits and underscores.')->required(),
            'schema' => $schema->string()->description('The schema name the pool connects to.')->required(),
            'mode' => $schema->string()->enum(array_column(PoolMode::cases(), 'value'))->description('The pooling mode.')->required(),
            'size' => $schema->integer()->description('Server connections in the pool, 1 to 500.')->required(),
        ];
    }
}
