<?php

declare(strict_types=1);

namespace App\Mcp\Tools\ServerProviders;

use App\Actions\Providers\AddServerProvider;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Requests\Providers\StoreServerProviderRequest;
use App\Http\Resources\Api\V1\ServerProviderResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_server_provider')]
#[Description('Add a server provider (cloud account) to a project. The credentials are checked against the provider first.')]
#[RequiresAccess(AccessArea::ServerProviders, AccessLevel::Write)]
class CreateServerProviderTool extends McpTool
{
    public function respond(StoreServerProviderRequest $request, AddServerProvider $add): Response
    {
        return $this->json(new ServerProviderResource($add->handle($request->toProvider())));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->uuid($schema, 'The project to add it to.')->required(),
            'name' => $schema->string()->description('A unique name for the provider.')->required(),
            'type' => $schema->string()->description('The provider type from list_server_provider_types.')->required(),
            'credentials' => $schema->object()->description('The credential fields this type needs, as listed by the matching list_*_types tool.')->required(),
        ];
    }
}
