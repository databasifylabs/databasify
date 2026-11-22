<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Upgrades\UpgradeDatabase;
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

#[Name('upgrade_database')]
#[Description('Upgrade the database to its available engine image, node by node. It runs in the background; poll get_database until the status settles.')]
#[IsDestructive]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Write)]
class UpgradeDatabaseTool extends McpTool
{
    public function respond(Database $database, UpgradeDatabase $upgradeDatabase): Response
    {
        $upgradeDatabase->handle($database);

        return $this->json(new DatabaseResource($database->refresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
        ];
    }
}
