<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Lifecycle\StopOperation;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Resources\Api\V1\DatabaseResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('stop_database_operation')]
#[Description('Stop the operation running on a database and cancel its engine tasks.')]
#[IsDestructive]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Write)]
class StopDatabaseOperationTool extends McpTool
{
    public function respond(Database $database, StopOperation $stop): Response
    {
        $stop->handle($database);

        return $this->json(new DatabaseResource($database->refresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
        ];
    }
}
