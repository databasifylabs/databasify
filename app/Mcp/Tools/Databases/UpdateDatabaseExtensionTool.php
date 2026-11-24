<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Extensions\UpdateSchemaExtension;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Requests\Databases\SchemaExtensionRequest;
use App\Http\Resources\Api\V1\DatabaseResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use App\Models\Schema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_database_extension')]
#[Description("Update an extension in a schema to the version the image ships. Updates can't be undone. It runs in the background; poll get_database until the status settles.")]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Write)]
class UpdateDatabaseExtensionTool extends McpTool
{
    public function respond(SchemaExtensionRequest $request, Database $database, Schema $schema, UpdateSchemaExtension $update): Response
    {
        $update->handle($schema, $request->extensionName());

        return $this->json(new DatabaseResource($database->refresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
            'schema' => $this->uuid($schema, 'The schema id.')->required(),
            'name' => $schema->string()->description('The extension name, such as vector.')->required(),
        ];
    }
}
