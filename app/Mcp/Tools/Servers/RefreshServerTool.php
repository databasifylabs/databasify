<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Servers;

use App\Actions\Servers\ReadServerFacts;
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

#[Name('refresh_server')]
#[Description('Read the operating system, CPUs, memory and disk of a server again over SSH.')]
#[RequiresAccess(AccessArea::Servers, AccessLevel::Write)]
class RefreshServerTool extends McpTool
{
    public function respond(Server $server, ReadServerFacts $readServerFacts): Response
    {
        $readServerFacts->handle($server);

        return $this->json(new ServerResource($server->refresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'server' => $this->uuid($schema, 'The server id.')->required(),
        ];
    }
}
