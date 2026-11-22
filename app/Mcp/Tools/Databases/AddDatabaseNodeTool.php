<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Nodes\AddDatabaseNode;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Enums\Databases\NodeKind;
use App\Http\Requests\Databases\AddDatabaseNodeRequest;
use App\Http\Resources\Api\V1\DatabaseResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('add_database_node')]
#[Description('Add a standby (member) or a read-only node on a ready server on the same private network. It runs in the background; poll get_database until the status settles.')]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Write)]
class AddDatabaseNodeTool extends McpTool
{
    public function respond(AddDatabaseNodeRequest $request, Database $database, AddDatabaseNode $addDatabaseNode): Response
    {
        $addDatabaseNode->handle($database, $request->toNode());

        return $this->json(new DatabaseResource($database->refresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
            'server_id' => $this->uuid($schema, 'The ready server to add.')->required(),
            'kind' => $schema->string()->enum(array_column(NodeKind::cases(), 'value'))->description('member (default) or read_only.'),
            'name' => $schema->string()->description('The name of a read-only node (read_only only).'),
        ];
    }
}
