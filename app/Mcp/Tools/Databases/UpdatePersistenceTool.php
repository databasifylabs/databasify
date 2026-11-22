<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Configuration\UpdatePersistence;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Enums\Databases\Persistence;
use App\Http\Requests\Databases\UpdatePersistenceRequest;
use App\Http\Resources\Api\V1\DatabaseResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('update_persistence')]
#[Description('Turn disk snapshots on or off for a key-value engine. Engines that always persist refuse. It runs in the background; poll get_database until the status settles.')]
#[IsDestructive]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Write)]
class UpdatePersistenceTool extends McpTool
{
    public function respond(UpdatePersistenceRequest $request, Database $database, UpdatePersistence $updatePersistence): Response
    {
        $updatePersistence->handle($database, $request->persistence());

        return $this->json(new DatabaseResource($database->refresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
            'persistence' => $schema->string()->enum(array_column(Persistence::cases(), 'value'))->description('snapshots or off.')->required(),
        ];
    }
}
