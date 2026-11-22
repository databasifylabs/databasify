<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Servers;

use App\Actions\Servers\DeleteServer;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Server;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('delete_server')]
#[Description('Remove a server from Databasify, and optionally destroy it at its provider. Refused while it is part of a database.')]
#[IsDestructive]
#[RequiresAccess(AccessArea::Servers, AccessLevel::Write)]
class DeleteServerTool extends McpTool
{
    public function respond(Request $request, Server $server, DeleteServer $delete): Response
    {
        $delete->handle($server, $request->boolean('destroy_at_provider'));

        return Response::json(['deleted' => $server->id]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'server' => $this->uuid($schema, 'The server id.')->required(),
            'destroy_at_provider' => $schema->boolean()->description('Also destroy the server at its provider.'),
        ];
    }
}
