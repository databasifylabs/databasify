<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Accounts\DeleteDatabaseUser;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use App\Models\DatabaseUser;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('delete_database_user')]
#[Description('Delete a database user. The default user cannot be deleted.')]
#[IsDestructive]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Write)]
class DeleteDatabaseUserTool extends McpTool
{
    public function respond(Database $database, DatabaseUser $user, DeleteDatabaseUser $delete): Response
    {
        $delete->handle($user);

        return Response::json(['deleted' => $user->id]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
            'user' => $this->uuid($schema, 'The database user id.')->required(),
        ];
    }
}
