<?php

declare(strict_types=1);

namespace App\Mcp\Tools\ServerProviders;

use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\ServerProvider;
use App\Services\Providers\ProviderCatalog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_provider_networks')]
#[Description('List the private networks a provider has in a region, read live from the provider\'s API.')]
#[IsReadOnly]
#[RequiresAccess(AccessArea::ServerProviders, AccessLevel::Read)]
class ListProviderNetworksTool extends McpTool
{
    public function respond(Request $request, ServerProvider $provider, ProviderCatalog $catalog): Response
    {
        $request->validate(['region' => ['required', 'string']]);

        return Response::json($catalog->networksIn($provider, $request->string('region')->value()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'provider' => $this->uuid($schema, 'The server provider id.')->required(),
            'region' => $schema->string()->description('The region id from list_provider_regions.')->required(),
        ];
    }
}
