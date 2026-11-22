<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
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

#[Name('refresh_database')]
#[Description('Read the live status and engine version of every node now.')]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Write)]
class RefreshDatabaseTool extends McpTool
{
    public function respond(Database $database, RefreshDatabaseStatus $refresh): Response
    {
        $refresh->handle($database);

        return $this->json(new DatabaseResource($database->refresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
        ];
    }
}
