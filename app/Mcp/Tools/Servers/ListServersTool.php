<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Servers;

use App\DTOs\Servers\ServerFilters;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Enums\Servers\ServerStatus;
use App\Http\Resources\Api\V1\ServerResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Queries\Servers\ServerQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_servers')]
#[Description('List servers with their status, addresses, provider, size and operating system. Filter by search text or status. Pages of 25; pass next_cursor for more.')]
#[IsReadOnly]
#[RequiresAccess(AccessArea::Servers, AccessLevel::Read)]
class ListServersTool extends McpTool
{
    public function respond(Request $request): Response
    {
        return $this->page(ServerQuery::filteredBy(ServerFilters::fromRequest($request)), ServerResource::class);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->uuid($schema, 'Only list this project. Omit to list every project the key can reach.'),
            'search' => $schema->string()->description('Match part of the server name or IP address.'),
            'status' => $schema->string()->enum(array_column(ServerStatus::cases(), 'value'))->description('Only servers in this status.'),
            'cursor' => $this->cursor($schema),
        ];
    }
}
