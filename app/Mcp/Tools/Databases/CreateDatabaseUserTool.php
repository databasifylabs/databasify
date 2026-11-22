<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Accounts\CreateDatabaseUser;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Requests\Databases\StoreDatabaseUserRequest;
use App\Http\Resources\Api\V1\DatabaseUserCredentialsResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_database_user')]
#[Description('Create a database user. The response holds its generated password and connection details.')]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Write)]
class CreateDatabaseUserTool extends McpTool
{
    public function respond(StoreDatabaseUserRequest $request, Database $database, CreateDatabaseUser $create): Response
    {
        return $this->json(new DatabaseUserCredentialsResource($create->handle($database, $request->userName(), $request->encryption())));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
            'name' => $schema->string()->description('Letters, digits and underscores, starting with a letter.')->required(),
            'password_encryption' => $schema->string()->description('The password hashing method, where the engine offers a choice.'),
        ];
    }
}
