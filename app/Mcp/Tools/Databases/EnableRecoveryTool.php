<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Recovery\EnablePointInTimeRecovery;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Requests\Databases\EnableRecoveryRequest;
use App\Http\Resources\Api\V1\DatabaseResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('enable_recovery')]
#[Description('Turn on point-in-time recovery: continuous WAL or oplog archiving plus daily base backups to a storage provider of the same project. It runs in the background; poll get_database until the status settles.')]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Write)]
class EnableRecoveryTool extends McpTool
{
    public function respond(EnableRecoveryRequest $request, Database $database, EnablePointInTimeRecovery $enablePointInTimeRecovery): Response
    {
        $enablePointInTimeRecovery->handle($database, $request->toSettings());

        return $this->json(new DatabaseResource($database->refresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
            'storage_provider_id' => $this->uuid($schema, 'The storage provider from list_storage_providers.')->required(),
            'retention_days' => $schema->integer()->description('How many days of backups to keep.')->required(),
            'backup_hour' => $schema->integer()->description('The UTC hour (0-23) of the daily base backup.')->required(),
        ];
    }
}
