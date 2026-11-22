<?php

declare(strict_types=1);

namespace App\Mcp\Tools\ServerProviders;

use App\Actions\Networks\DeleteNetwork;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Network;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('delete_network')]
#[Description('Delete an empty private network from Databasify, and optionally at the provider too.')]
#[IsDestructive]
#[RequiresAccess(AccessArea::ServerProviders, AccessLevel::Write)]
class DeleteNetworkTool extends McpTool
{
    public function respond(Request $request, Network $network, DeleteNetwork $delete): Response
    {
        $delete->handle($network, $request->boolean('destroy_at_provider'));

        return Response::json(['deleted' => $network->id]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'network' => $this->uuid($schema, 'The network id.')->required(),
            'destroy_at_provider' => $schema->boolean()->description('Also delete the network at the provider.'),
        ];
    }
}
