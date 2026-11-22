<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Servers;

use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Resources\Api\V1\ServerUserResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Server;
use App\Queries\Servers\ServerUserQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_server_users')]
#[Description('List the Linux users Databasify created on a server. Pages of 25; pass next_cursor for more.')]
#[IsReadOnly]
#[RequiresAccess(AccessArea::Servers, AccessLevel::Read)]
class ListServerUsersTool extends McpTool
{
    public function respond(Server $server): Response
    {
        return $this->page(ServerUserQuery::of($server), ServerUserResource::class);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'server' => $this->uuid($schema, 'The server id.')->required(),
            'cursor' => $this->cursor($schema),
        ];
    }
}
