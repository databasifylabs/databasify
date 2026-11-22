<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Migrations\StopMigration;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Resources\Api\V1\DatabaseResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use App\Models\DatabaseMigration;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('finish_migration')]
#[Description('Cut over a syncing migration: stop replication and make this database the one to use. It runs in the background; poll get_database until the status settles.')]
#[IsDestructive]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Write)]
class FinishMigrationTool extends McpTool
{
    public function respond(Database $database, DatabaseMigration $migration, StopMigration $stop): Response
    {
        $stop->handle($migration, cutover: true);

        return $this->json(new DatabaseResource($database->refresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
            'migration' => $this->uuid($schema, 'The migration id from list_migrations.')->required(),
        ];
    }
}
