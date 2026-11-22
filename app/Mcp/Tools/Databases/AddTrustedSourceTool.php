<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Network\AddNodeTrustedSource;
use App\Actions\Databases\Network\AddTrustedSource;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Requests\Databases\StoreTrustedSourceRequest;
use App\Http\Resources\Api\V1\DatabaseNodeResource;
use App\Http\Resources\Api\V1\DatabaseResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use App\Models\DatabaseNode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('add_trusted_source')]
#[Description('Allow an IPv4 address or range to connect to the database or to one read-only node. A single address becomes a /32. It runs in the background; poll get_database until the status settles.')]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Write)]
class AddTrustedSourceTool extends McpTool
{
    public function respond(StoreTrustedSourceRequest $request, Database $database, ?DatabaseNode $node, AddTrustedSource $add, AddNodeTrustedSource $addToNode): Response
    {
        if ($node === null) {
            $add->handle($database, $request->toSource());

            return $this->json(new DatabaseResource($database->refresh()));
        }

        $addToNode->handle($node, $request->toSource());

        return $this->json(new DatabaseNodeResource($node->refresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
            'node' => $this->uuid($schema, 'A read-only node id, to use that node instead of the whole database.'),
            'cidr' => $schema->string()->description('An IPv4 address or CIDR range, such as 203.0.113.7 or 198.51.100.0/24.')->required(),
            'label' => $schema->string()->description('A note, such as office.'),
        ];
    }
}
