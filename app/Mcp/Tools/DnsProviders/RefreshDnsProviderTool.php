<?php

declare(strict_types=1);

namespace App\Mcp\Tools\DnsProviders;

use App\Actions\Dns\ImportDnsZones;
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

#[Name('refresh_dns_provider')]
#[Description('Import the zones of a DNS provider again, picking up zones added at the provider.')]
#[RequiresAccess(AccessArea::DnsProviders, AccessLevel::Write)]
class RefreshDnsProviderTool extends McpTool
{
    public function respond(DnsProvider $provider, ImportDnsZones $import): Response
    {
        $import->handle($provider);

        return $this->json(DnsProviderResource::loaded($provider));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'provider' => $this->uuid($schema, 'The DNS provider id.')->required(),
        ];
    }
}
