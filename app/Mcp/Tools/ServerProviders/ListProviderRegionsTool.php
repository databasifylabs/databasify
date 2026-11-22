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
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_provider_regions')]
#[Description('List the regions a server provider offers, read live from the provider\'s API.')]
#[IsReadOnly]
#[RequiresAccess(AccessArea::ServerProviders, AccessLevel::Read)]
class ListProviderRegionsTool extends McpTool
{
    public function respond(ServerProvider $provider, ProviderCatalog $catalog): Response
    {
        return Response::json($catalog->regionsOf($provider));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'provider' => $this->uuid($schema, 'The server provider id.')->required(),
        ];
    }
}
