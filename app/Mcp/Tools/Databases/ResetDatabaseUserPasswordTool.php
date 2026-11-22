<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Accounts\ResetDatabaseUserPassword;
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
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('reset_database_user_password')]
#[Description('Give a database user a new generated password and return it.')]
#[IsDestructive]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Write)]
class ResetDatabaseUserPasswordTool extends McpTool
{
    public function respond(Database $database, DatabaseUser $user, ResetDatabaseUserPassword $reset): Response
    {
        return $this->json(new DatabaseUserCredentialsResource($reset->handle($user)));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
            'user' => $this->uuid($schema, 'The database user id.')->required(),
        ];
    }
}
