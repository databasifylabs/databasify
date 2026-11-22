<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Upgrades\CheckDatabaseUpgrade;
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

#[Name('check_database_upgrade')]
#[Description('Look up a newer engine image for this database and record it as available_version.')]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Write)]
class CheckDatabaseUpgradeTool extends McpTool
{
    public function respond(Database $database, CheckDatabaseUpgrade $check): Response
    {
        $check->handle($database);

        return $this->json(new DatabaseResource($database->refresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
        ];
    }
}
