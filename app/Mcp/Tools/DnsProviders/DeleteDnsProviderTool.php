<?php

declare(strict_types=1);

namespace App\Mcp\Tools\DnsProviders;

use App\Actions\Dns\DeleteDnsProvider;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\DnsProvider;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('delete_dns_provider')]
#[Description('Delete a DNS provider. Refused while a database uses one of its domains.')]
#[IsDestructive]
#[RequiresAccess(AccessArea::DnsProviders, AccessLevel::Write)]
class DeleteDnsProviderTool extends McpTool
{
    public function respond(DnsProvider $provider, DeleteDnsProvider $delete): Response
    {
        $delete->handle($provider);

        return Response::json(['deleted' => $provider->id]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'provider' => $this->uuid($schema, 'The DNS provider id.')->required(),
        ];
    }
}
