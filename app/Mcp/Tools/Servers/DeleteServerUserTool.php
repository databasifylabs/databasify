<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Servers;

use App\Actions\Servers\DeleteServerUser;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Server;
use App\Models\ServerUser;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('delete_server_user')]
#[Description('Delete a Linux user that Databasify created on a server.')]
#[IsDestructive]
#[RequiresAccess(AccessArea::Servers, AccessLevel::Write)]
class DeleteServerUserTool extends McpTool
{
    public function respond(Server $server, ServerUser $user, DeleteServerUser $delete): Response
    {
        $delete->handle($user);

        return Response::json(['deleted' => $user->id]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'server' => $this->uuid($schema, 'The server id.')->required(),
            'user' => $this->uuid($schema, 'The server user id.')->required(),
        ];
    }
}
