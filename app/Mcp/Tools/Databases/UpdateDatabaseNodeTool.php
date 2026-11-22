<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Nodes\RenameDatabaseNode;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Requests\Databases\UpdateDatabaseNodeRequest;
use App\Http\Resources\Api\V1\DatabaseNodeResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use App\Models\DatabaseNode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_database_node')]
#[Description('Rename a read-only node. Its hostname changes too.')]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Write)]
class UpdateDatabaseNodeTool extends McpTool
{
    public function respond(UpdateDatabaseNodeRequest $request, Database $database, DatabaseNode $node, RenameDatabaseNode $rename): Response
    {
        $rename->handle($node, $request->nodeName());

        return $this->json(new DatabaseNodeResource($node->refresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
            'node' => $this->uuid($schema, 'The node id from list_database_nodes.')->required(),
            'name' => $schema->string()->description('The new node name.')->required(),
        ];
    }
}
