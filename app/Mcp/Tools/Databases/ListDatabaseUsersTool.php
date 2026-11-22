<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Resources\Api\V1\DatabaseUserResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_database_users')]
#[Description('List the users of a database. Passwords are not included; use get_database_user_credentials.')]
#[IsReadOnly]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Read)]
class ListDatabaseUsersTool extends McpTool
{
    public function respond(Database $database): Response
    {
        return $this->json(DatabaseUserResource::collection($database->users));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
        ];
    }
}
