<?php

declare(strict_types=1);

namespace App\Mcp\Tools\ServerProviders;

use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Resources\Api\V1\ServerProviderResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\ServerProvider;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_server_provider')]
#[Description('Get one server provider with its server and network counts.')]
#[IsReadOnly]
#[RequiresAccess(AccessArea::ServerProviders, AccessLevel::Read)]
class GetServerProviderTool extends McpTool
{
    public function respond(ServerProvider $provider): Response
    {
        return $this->json(ServerProviderResource::loaded($provider));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'provider' => $this->uuid($schema, 'The server provider id.')->required(),
        ];
    }
}
