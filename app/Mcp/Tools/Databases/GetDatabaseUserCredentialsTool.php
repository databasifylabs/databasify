<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Resources\Api\V1\DatabaseUserCredentialsResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use App\Models\DatabaseUser;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get_database_user_credentials')]
#[Description('Get the password and connection details of a database user. Needs write access because it reveals a secret.')]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Write)]
class GetDatabaseUserCredentialsTool extends McpTool
{
    public function respond(Database $database, DatabaseUser $user): Response
    {
        return $this->json(new DatabaseUserCredentialsResource($user));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
            'user' => $this->uuid($schema, 'The database user id.')->required(),
        ];
    }
}
