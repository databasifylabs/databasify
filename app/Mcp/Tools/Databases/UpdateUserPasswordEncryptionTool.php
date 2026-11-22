<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Accounts\ChangePasswordEncryption;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Requests\Databases\UpdatePasswordEncryptionRequest;
use App\Http\Resources\Api\V1\DatabaseUserResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use App\Models\DatabaseUser;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_user_password_encryption')]
#[Description('Change how the password of a database user is hashed.')]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Write)]
class UpdateUserPasswordEncryptionTool extends McpTool
{
    public function respond(UpdatePasswordEncryptionRequest $request, Database $database, DatabaseUser $user, ChangePasswordEncryption $change): Response
    {
        return $this->json(new DatabaseUserResource($change->handle($user, $request->encryption())));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
            'user' => $this->uuid($schema, 'The database user id.')->required(),
            'password_encryption' => $schema->string()->description('The password hashing method the engine offers.')->required(),
        ];
    }
}
