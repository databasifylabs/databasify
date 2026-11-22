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
use Illuminate\Http\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('cancel_migration')]
#[Description('Cancel a migration without cutting over. It runs in the background; poll get_database until the status settles.')]
#[IsDestructive]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Write)]
class CancelMigrationTool extends McpTool
{
    public function respond(Request $request, Database $database, DatabaseMigration $migration, StopMigration $stop): Response
    {
        $stop->handle($migration, cutover: false, force: $request->boolean('force'));

        return $this->json(new DatabaseResource($database->refresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
            'migration' => $this->uuid($schema, 'The migration id from list_migrations.')->required(),
            'force' => $schema->boolean()->description('Cancel even if the engine cannot be reached.'),
        ];
    }
}
