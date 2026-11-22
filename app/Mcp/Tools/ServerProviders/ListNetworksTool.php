<?php

declare(strict_types=1);

namespace App\Mcp\Tools\ServerProviders;

use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Resources\Api\V1\NetworkResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Queries\Networks\NetworkQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_networks')]
#[Description('List the private networks Databasify manages, with their CIDR, region and server count. Pages of 25; pass next_cursor for more.')]
#[IsReadOnly]
#[RequiresAccess(AccessArea::ServerProviders, AccessLevel::Read)]
class ListNetworksTool extends McpTool
{
    public function respond(): Response
    {
        return $this->page(NetworkQuery::withUsage(), NetworkResource::class);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->uuid($schema, 'Only list this project. Omit to list every project the key can reach.'),
            'cursor' => $this->cursor($schema),
        ];
    }
}
