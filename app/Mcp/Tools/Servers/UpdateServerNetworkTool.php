<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Servers;

use App\Actions\Servers\UpdateServerNetwork;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Requests\Servers\UpdateServerNetworkRequest;
use App\Http\Resources\Api\V1\ServerResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Server;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_server_network')]
#[Description('Change the private network of a server.')]
#[RequiresAccess(AccessArea::Servers, AccessLevel::Write)]
class UpdateServerNetworkTool extends McpTool
{
    public function respond(UpdateServerNetworkRequest $request, Server $server, UpdateServerNetwork $update): Response
    {
        $update->handle($server, $request->toPrivateNetwork());

        return $this->json(new ServerResource($server->refresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'server' => $this->uuid($schema, 'The server id.')->required(),
            'network' => $schema->string()->description('A network id from list_networks, new for a new network, or wireguard. Omit for no private network.'),
            'network_name' => $schema->string()->description('The name of a new network (with network new).'),
            'network_cidr' => $schema->string()->description('The CIDR of a new network, such as 10.10.0.0/16 (with network new).'),
            'network_block' => $schema->integer()->description('The WireGuard block prefix length, such as 24 (with network wireguard).'),
            'private_ip' => $schema->string()->description('The private IPv4 address inside the chosen network.'),
        ];
    }
}
