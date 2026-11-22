<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Recovery\DisablePointInTimeRecovery;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Resources\Api\V1\DatabaseResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('disable_recovery')]
#[Description('Turn off point-in-time recovery, optionally deleting the backups. It runs in the background; poll get_database until the status settles.')]
#[IsDestructive]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Write)]
class DisableRecoveryTool extends McpTool
{
    public function respond(Request $request, Database $database, DisablePointInTimeRecovery $disable): Response
    {
        $disable->handle($database, $request->boolean('delete_backups'));

        return $this->json(new DatabaseResource($database->refresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
            'delete_backups' => $schema->boolean()->description('Also delete the backups in object storage.'),
        ];
    }
}
