<?php

declare(strict_types=1);

namespace App\Mcp\Tools\DnsProviders;

use App\Actions\Dns\AddDnsProvider;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Requests\Dns\StoreDnsProviderRequest;
use App\Http\Resources\Api\V1\DnsProviderResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_dns_provider')]
#[Description('Add a DNS provider to a project and import its zones.')]
#[RequiresAccess(AccessArea::DnsProviders, AccessLevel::Write)]
class CreateDnsProviderTool extends McpTool
{
    public function respond(StoreDnsProviderRequest $request, AddDnsProvider $add): Response
    {
        return $this->json(DnsProviderResource::loaded($add->handle($request->toProvider())));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->uuid($schema, 'The project to add it to.')->required(),
            'name' => $schema->string()->description('A unique name for the provider.')->required(),
            'type' => $schema->string()->description('The provider type from list_dns_provider_types.')->required(),
            'credentials' => $schema->object()->description('The credential fields this type needs, as listed by the matching list_*_types tool.')->required(),
        ];
    }
}
