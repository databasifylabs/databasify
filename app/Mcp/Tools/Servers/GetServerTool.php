<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Servers;

use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Resources\Api\V1\ServerResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Server;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_server')]
#[Description('Get one server with its status, hardware facts, private network and the database node it runs, if any.')]
#[IsReadOnly]
#[RequiresAccess(AccessArea::Servers, AccessLevel::Read)]
class GetServerTool extends McpTool
{
    public function respond(Server $server): Response
    {
        return $this->json(new ServerResource($server->load('databaseNodes.database')));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'server' => $this->uuid($schema, 'The server id.')->required(),
        ];
    }
}
