<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Resources\Api\V1\TrustedSourceResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use App\Models\DatabaseNode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_trusted_sources')]
#[Description('List the IPv4 ranges allowed to connect to a database, or to one read-only node.')]
#[IsReadOnly]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Read)]
class ListTrustedSourcesTool extends McpTool
{
    public function respond(Database $database, ?DatabaseNode $node): Response
    {
        return $this->json(TrustedSourceResource::collection($node === null ? $database->trustedSources : $node->trustedSources));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
            'node' => $this->uuid($schema, 'A read-only node id, to use that node instead of the whole database.'),
        ];
    }
}
