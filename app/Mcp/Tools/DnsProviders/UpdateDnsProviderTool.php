<?php

declare(strict_types=1);

namespace App\Mcp\Tools\DnsProviders;

use App\Actions\Dns\UpdateDnsProvider;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Requests\Dns\UpdateDnsProviderRequest;
use App\Http\Resources\Api\V1\DnsProviderResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\DnsProvider;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_dns_provider')]
#[Description('Rename a DNS provider or replace its credentials. Credentials left out stay as they are.')]
#[RequiresAccess(AccessArea::DnsProviders, AccessLevel::Write)]
class UpdateDnsProviderTool extends McpTool
{
    public function respond(UpdateDnsProviderRequest $request, DnsProvider $provider, UpdateDnsProvider $update): Response
    {
        return $this->json(DnsProviderResource::loaded($update->handle($provider, $request->toProvider($provider))));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'provider' => $this->uuid($schema, 'The DNS provider id.')->required(),
            'name' => $schema->string()->description('The provider name.')->required(),
            'credentials' => $schema->object()->description('The credential fields this type needs, as listed by the matching list_*_types tool.'),
        ];
    }
}
