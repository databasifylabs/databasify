<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Resources\Api\V1\PointInTimeRecoveryResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_recovery')]
#[Description('Get the point-in-time recovery settings and backup health of a database.')]
#[IsReadOnly]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Read)]
class GetRecoveryTool extends McpTool
{
    public function respond(Database $database): Response
    {
        return $this->json(new PointInTimeRecoveryResource($database->pointInTimeRecovery ?? throw ValidationException::withMessages(['database' => "{$database->name} has no point-in-time recovery."])));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
        ];
    }
}
