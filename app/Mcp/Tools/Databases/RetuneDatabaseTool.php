<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Configuration\RetuneDatabase;
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

#[Name('retune_database')]
#[Description('Recompute and apply the engine settings for the current server sizes. It runs in the background; poll get_database until the status settles.')]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Write)]
class RetuneDatabaseTool extends McpTool
{
    public function respond(Database $database, RetuneDatabase $retuneDatabase): Response
    {
        $retuneDatabase->handle($database);

        return $this->json(new DatabaseResource($database->refresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
        ];
    }
}
