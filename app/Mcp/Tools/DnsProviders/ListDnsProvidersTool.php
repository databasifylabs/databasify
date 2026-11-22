<?php

declare(strict_types=1);

namespace App\Mcp\Tools\DnsProviders;

use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Resources\Api\V1\DnsProviderResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Queries\Dns\DnsProviderQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_dns_providers')]
#[Description('List DNS providers with their zones and how many databases use each zone. Credentials are never returned. Pages of 25; pass next_cursor for more.')]
#[IsReadOnly]
#[RequiresAccess(AccessArea::DnsProviders, AccessLevel::Read)]
class ListDnsProvidersTool extends McpTool
{
    public function respond(): Response
    {
        return $this->page(DnsProviderQuery::withZones(), DnsProviderResource::class);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->uuid($schema, 'Only list this project. Omit to list every project the key can reach.'),
            'cursor' => $this->cursor($schema),
        ];
    }
}
