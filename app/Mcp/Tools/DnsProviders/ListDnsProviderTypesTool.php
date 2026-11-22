<?php

declare(strict_types=1);

namespace App\Mcp\Tools\DnsProviders;

use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\DnsProvider;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_dns_provider_types')]
#[Description('List the DNS provider types this install supports, with the credential fields each needs.')]
#[IsReadOnly]
#[RequiresAccess(AccessArea::DnsProviders, AccessLevel::Read)]
class ListDnsProviderTypesTool extends McpTool
{
    public function respond(): Response
    {
        return Response::json(DnsProvider::catalog());
    }
}
