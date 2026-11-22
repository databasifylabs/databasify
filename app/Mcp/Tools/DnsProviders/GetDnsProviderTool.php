<?php

declare(strict_types=1);

namespace App\Mcp\Tools\DnsProviders;

use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Resources\Api\V1\DnsProviderResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\DnsProvider;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_dns_provider')]
#[Description('Get one DNS provider with its zones.')]
#[IsReadOnly]
#[RequiresAccess(AccessArea::DnsProviders, AccessLevel::Read)]
class GetDnsProviderTool extends McpTool
{
    public function respond(DnsProvider $provider): Response
    {
        return $this->json(DnsProviderResource::loaded($provider));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'provider' => $this->uuid($schema, 'The DNS provider id.')->required(),
        ];
    }
}
