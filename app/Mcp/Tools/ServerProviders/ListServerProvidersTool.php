<?php

declare(strict_types=1);

namespace App\Mcp\Tools\ServerProviders;

use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Resources\Api\V1\ServerProviderResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Queries\Providers\ServerProviderQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_server_providers')]
#[Description('List server providers (cloud accounts) with their server and network counts. Credentials are never returned. Pages of 25; pass next_cursor for more.')]
#[IsReadOnly]
#[RequiresAccess(AccessArea::ServerProviders, AccessLevel::Read)]
class ListServerProvidersTool extends McpTool
{
    public function respond(): Response
    {
        return $this->page(ServerProviderQuery::withUsage(), ServerProviderResource::class);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->uuid($schema, 'Only list this project. Omit to list every project the key can reach.'),
            'cursor' => $this->cursor($schema),
        ];
    }
}
