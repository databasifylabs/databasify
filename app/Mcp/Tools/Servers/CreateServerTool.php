<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Servers;

use App\Actions\Servers\AddServer;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Requests\Servers\StoreServerRequest;
use App\Http\Resources\Api\V1\ServerResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_server')]
#[Description('Add an existing server by SSH. Add Databasify\'s key from get_server_ssh_key first, or pass a root password once. Provisioning runs in the background: poll get_server until it is ready.')]
#[RequiresAccess(AccessArea::Servers, AccessLevel::Write)]
class CreateServerTool extends McpTool
{
    public function respond(StoreServerRequest $request, AddServer $add): Response
    {
        return $this->json(new ServerResource($add->handle($request->toServer())));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->uuid($schema, 'The project to add it to.')->required(),
            'name' => $schema->string()->description('Lowercase letters, digits and dashes; unique across servers.')->required(),
            'public_ip' => $schema->string()->description('The public IPv4 address.')->required(),
            'ssh_port' => $schema->integer()->description('The SSH port, usually 22.')->required(),
            'ssh_user' => $schema->string()->description('The SSH user, usually root.')->required(),
            'ssh_password' => $schema->string()->description('A password used once to install the Databasify key. Never stored.'),
            'disable_password_login' => $schema->boolean()->description('Turn off SSH password login after setup.'),
            'network' => $schema->string()->description('A network id from list_networks, new for a new network, or wireguard. Omit for no private network.'),
            'network_name' => $schema->string()->description('The name of a new network (with network new).'),
            'network_cidr' => $schema->string()->description('The CIDR of a new network, such as 10.10.0.0/16 (with network new).'),
            'network_block' => $schema->integer()->description('The WireGuard block prefix length, such as 24 (with network wireguard).'),
            'private_ip' => $schema->string()->description('The private IPv4 address inside the chosen network.'),
        ];
    }
}
