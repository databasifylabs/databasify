<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Domains\AssignDatabaseZone;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Requests\Databases\UpdateDatabaseDomainRequest;
use App\Http\Resources\Api\V1\DatabaseResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_database_domain')]
#[Description('Move a database to a DNS zone of a DNS provider, or back to Databasify\'s default domain with null. The nodes are redeployed. It runs in the background; poll get_database until the status settles.')]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Write)]
class UpdateDatabaseDomainTool extends McpTool
{
    public function respond(UpdateDatabaseDomainRequest $request, Database $database, AssignDatabaseZone $assignDatabaseZone): Response
    {
        $assignDatabaseZone->handle($database, $request->zone());

        return $this->json(new DatabaseResource($database->refresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
            'dns_zone_id' => $schema->string()->format('uuid')->description('A zone id from list_dns_providers, or null for the default domain.'),
        ];
    }
}
