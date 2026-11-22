<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Recovery\RestoreToPointInTime;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Requests\Databases\RestoreToPointInTimeRequest;
use App\Http\Resources\Api\V1\DatabaseResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('restore_database')]
#[Description('Restore a database to a point in time as a new database on a ready server. The source database is untouched. It runs in the background; poll get_database until the status settles.')]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Write)]
class RestoreDatabaseTool extends McpTool
{
    public function respond(RestoreToPointInTimeRequest $request, Database $database, RestoreToPointInTime $restore): Response
    {
        return $this->json(new DatabaseResource($restore->handle($database, $request->toRestore())->refresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
            'name' => $schema->string()->description('The name of the new database.')->required(),
            'server_id' => $this->uuid($schema, 'The ready server for the new database.')->required(),
            'target' => $schema->string()->format('date-time')->description('The moment to restore to, inside the restorable window of get_recovery.')->required(),
        ];
    }
}
