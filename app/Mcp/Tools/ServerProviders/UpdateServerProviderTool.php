<?php

declare(strict_types=1);

namespace App\Mcp\Tools\ServerProviders;

use App\Actions\Providers\UpdateServerProvider;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Requests\Providers\UpdateServerProviderRequest;
use App\Http\Resources\Api\V1\ServerProviderResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\ServerProvider;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_server_provider')]
#[Description('Rename a server provider or replace its credentials. Credentials left out stay as they are.')]
#[RequiresAccess(AccessArea::ServerProviders, AccessLevel::Write)]
class UpdateServerProviderTool extends McpTool
{
    public function respond(UpdateServerProviderRequest $request, ServerProvider $provider, UpdateServerProvider $update): Response
    {
        return $this->json(new ServerProviderResource($update->handle($provider, $request->toProvider($provider))));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'provider' => $this->uuid($schema, 'The server provider id.')->required(),
            'name' => $schema->string()->description('The provider name.')->required(),
            'credentials' => $schema->object()->description('The credential fields this type needs, as listed by the matching list_*_types tool.'),
        ];
    }
}
