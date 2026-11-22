<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Servers;

use App\Actions\Servers\QueueProvisioning;
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

#[Name('provision_server')]
#[Description('Run the server setup again, for example after a failed setup. It runs in the background; poll get_server.')]
#[RequiresAccess(AccessArea::Servers, AccessLevel::Write)]
class ProvisionServerTool extends McpTool
{
    public function respond(Server $server, QueueProvisioning $queueProvisioning): Response
    {
        $queueProvisioning->handle($server);

        return $this->json(new ServerResource($server->refresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'server' => $this->uuid($schema, 'The server id.')->required(),
        ];
    }
}
