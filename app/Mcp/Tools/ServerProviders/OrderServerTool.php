<?php

declare(strict_types=1);

namespace App\Mcp\Tools\ServerProviders;

use App\Actions\Providers\OrderProviderServer;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Enums\Servers\OperatingSystem;
use App\Http\Requests\Api\V1\OrderServerRequest;
use App\Http\Resources\Api\V1\ServerResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\ServerProvider;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('order_server')]
#[Description('Order a new server from a provider. It runs in the background: the server starts as \'creating\'; poll get_server until it is ready.')]
#[RequiresAccess(AccessArea::ServerProviders, AccessLevel::Write)]
class OrderServerTool extends McpTool
{
    public function respond(OrderServerRequest $request, ServerProvider $provider, OrderProviderServer $order): Response
    {
        return $this->json(new ServerResource($order->handle($request->toOrder())));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'provider' => $this->uuid($schema, 'The server provider id.')->required(),
            'name' => $schema->string()->description('Lowercase letters, digits and dashes; unique across servers.')->required(),
            'region' => $schema->string()->description('The region id from list_provider_regions.')->required(),
            'plan' => $schema->string()->description('The plan id from list_provider_plans.')->required(),
            'os' => $schema->string()->enum(array_column(OperatingSystem::cases(), 'value'))->description('The operating system.')->required(),
            'network' => $schema->string()->description('A provider network id from list_provider_networks, or databasify:new for a new network.'),
            'network_block' => $schema->integer()->description('The prefix length of a new network, such as 24.'),
        ];
    }
}
