<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Configuration\UpdateDatabaseConfig;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Requests\Databases\UpdateDatabaseConfigRequest;
use App\Http\Resources\Api\V1\DatabaseResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_database_config')]
#[Description('Change the engine config of a database. Keys and allowed values come from the config_fields of get_database. It runs in the background; poll get_database until the status settles.')]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Write)]
class UpdateDatabaseConfigTool extends McpTool
{
    public function respond(UpdateDatabaseConfigRequest $request, Database $database, UpdateDatabaseConfig $updateDatabaseConfig): Response
    {
        $updateDatabaseConfig->handle($database, $request->config());

        return $this->json(new DatabaseResource($database->refresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
            'config' => $schema->object()->description('Config keys and string values; null resets a key.')->required(),
        ];
    }
}
