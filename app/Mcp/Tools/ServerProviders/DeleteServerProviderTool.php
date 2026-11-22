<?php

declare(strict_types=1);

namespace App\Mcp\Tools\ServerProviders;

use App\Actions\Providers\DeleteServerProvider;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\ServerProvider;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('delete_server_provider')]
#[Description('Delete a server provider. Refused while servers or networks still use it.')]
#[IsDestructive]
#[RequiresAccess(AccessArea::ServerProviders, AccessLevel::Write)]
class DeleteServerProviderTool extends McpTool
{
    public function respond(ServerProvider $provider, DeleteServerProvider $delete): Response
    {
        $delete->handle($provider);

        return Response::json(['deleted' => $provider->id]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'provider' => $this->uuid($schema, 'The server provider id.')->required(),
        ];
    }
}
