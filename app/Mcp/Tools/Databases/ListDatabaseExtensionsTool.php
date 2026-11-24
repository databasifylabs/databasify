<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Extensions\ReadAvailableExtensions;
use App\Actions\Databases\Extensions\ReadSchemaExtensions;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Resources\Api\V1\AvailableExtensionResource;
use App\Http\Resources\Api\V1\InstalledExtensionResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use App\Models\Schema;
use App\Support\Databases\ExtensionChanges;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_database_extensions')]
#[Description('List the extensions a database can enable, and the ones a schema has when a schema is given.')]
#[IsReadOnly]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Read)]
class ListDatabaseExtensionsTool extends McpTool
{
    public function respond(Database $database, ?Schema $schema, ReadAvailableExtensions $readAvailable, ReadSchemaExtensions $readInstalled, ExtensionChanges $changes): Response
    {
        return Response::json([
            'available' => AvailableExtensionResource::collection($readAvailable->handle($database))->resolve(),
            'enabled' => $schema === null ? [] : InstalledExtensionResource::collection($readInstalled->handle($schema))->resolve(),
            'change' => $changes->of($database)?->toArray(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
            'schema' => $this->uuid($schema, 'A schema id, to also list the extensions it has.'),
        ];
    }
}
