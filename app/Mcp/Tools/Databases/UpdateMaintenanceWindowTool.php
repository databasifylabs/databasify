<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Requests\Databases\UpdateMaintenanceWindowRequest;
use App\Http\Resources\Api\V1\DatabaseResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_maintenance_window')]
#[Description('Move the weekly maintenance window of a database (UTC day and hour).')]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Write)]
class UpdateMaintenanceWindowTool extends McpTool
{
    public function respond(UpdateMaintenanceWindowRequest $request, Database $database): Response
    {
        $database->moveMaintenanceWindowTo($request->toWindow());

        return $this->json(new DatabaseResource($database->refresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
            'day' => $schema->integer()->description('0 is Sunday, 6 is Saturday.')->required(),
            'hour' => $schema->integer()->description('The UTC hour, 0 to 23.')->required(),
        ];
    }
}
