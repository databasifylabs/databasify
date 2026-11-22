<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Servers;

use App\Actions\Servers\CreateServerUser;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Requests\Servers\StoreServerUserRequest;
use App\Http\Resources\Api\V1\ServerUserResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Server;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_server_user')]
#[Description('Create a Linux user on a server, optionally with an SSH public key.')]
#[RequiresAccess(AccessArea::Servers, AccessLevel::Write)]
class CreateServerUserTool extends McpTool
{
    public function respond(StoreServerUserRequest $request, Server $server, CreateServerUser $create): Response
    {
        $create->handle($server, $request->username(), $request->publicKey());

        return $this->json(new ServerUserResource($server->users()->where('username', $request->username())->firstOrFail()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'server' => $this->uuid($schema, 'The server id.')->required(),
            'username' => $schema->string()->description('Lowercase Linux user name.')->required(),
            'public_key' => $schema->string()->description('An SSH public key, such as ssh-ed25519 AAAA... me@laptop.'),
        ];
    }
}
