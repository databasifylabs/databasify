<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Network\RemoveNodeTrustedSource;
use App\Actions\Databases\Network\RemoveTrustedSource;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Resources\Api\V1\DatabaseNodeResource;
use App\Http\Resources\Api\V1\DatabaseResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\TrustedSource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('delete_trusted_source')]
#[Description('Remove an allowed IPv4 range from the database or from one read-only node. It runs in the background; poll get_database until the status settles.')]
#[IsDestructive]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Write)]
class DeleteTrustedSourceTool extends McpTool
{
    public function respond(Database $database, ?DatabaseNode $node, TrustedSource $trustedSource, RemoveTrustedSource $remove, RemoveNodeTrustedSource $removeFromNode): Response
    {
        if ($node === null) {
            $remove->handle($trustedSource);

            return $this->json(new DatabaseResource($database->refresh()));
        }

        $removeFromNode->handle($node, $trustedSource);

        return $this->json(new DatabaseNodeResource($node->refresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
            'node' => $this->uuid($schema, 'A read-only node id, to use that node instead of the whole database.'),
            'trusted_source' => $this->uuid($schema, 'The trusted source id from list_trusted_sources.')->required(),
        ];
    }
}
