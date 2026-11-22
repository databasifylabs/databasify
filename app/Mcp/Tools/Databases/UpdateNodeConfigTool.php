<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Configuration\UpdateNodeConfig;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Requests\Databases\UpdateDatabaseConfigRequest;
use App\Http\Resources\Api\V1\DatabaseNodeResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use App\Models\DatabaseNode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_node_config')]
#[Description('Change the engine config of a read-only node. Keys come from the config_fields of get_database. It runs in the background; poll get_database until the status settles.')]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Write)]
class UpdateNodeConfigTool extends McpTool
{
    public function respond(UpdateDatabaseConfigRequest $request, Database $database, DatabaseNode $node, UpdateNodeConfig $updateConfig): Response
    {
        $updateConfig->handle($node, $request->config());

        return $this->json(new DatabaseNodeResource($node->refresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
            'node' => $this->uuid($schema, 'The node id from list_database_nodes.')->required(),
            'config' => $schema->object()->description('Config keys and string values; null resets a key.')->required(),
        ];
    }
}
