<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Accounts\CreateSchema;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Requests\Databases\StoreSchemaRequest;
use App\Http\Resources\Api\V1\SchemaResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_schema')]
#[Description('Create a schema (logical database) inside a database.')]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Write)]
class CreateSchemaTool extends McpTool
{
    public function respond(StoreSchemaRequest $request, Database $database, CreateSchema $create): Response
    {
        return $this->json(new SchemaResource($create->handle($database, $request->schemaName())));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
            'name' => $schema->string()->description('The schema name.')->required(),
        ];
    }
}
