<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Recovery\UpdateRecoverySchedule;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Requests\Databases\UpdateRecoveryScheduleRequest;
use App\Http\Resources\Api\V1\DatabaseResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_recovery_schedule')]
#[Description('Change the backup retention and the hour of the daily base backup. It runs in the background; poll get_database until the status settles.')]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Write)]
class UpdateRecoveryScheduleTool extends McpTool
{
    public function respond(UpdateRecoveryScheduleRequest $request, Database $database, UpdateRecoverySchedule $updateRecoverySchedule): Response
    {
        $updateRecoverySchedule->handle($database, $request->toSchedule());

        return $this->json(new DatabaseResource($database->refresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
            'retention_days' => $schema->integer()->description('How many days of backups to keep.')->required(),
            'backup_hour' => $schema->integer()->description('The UTC hour (0-23) of the daily base backup.')->required(),
        ];
    }
}
