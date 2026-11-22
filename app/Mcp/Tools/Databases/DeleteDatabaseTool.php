<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Lifecycle\DeleteDatabase;
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

#[Name('delete_database')]
#[Description('Delete a database and its containers. It runs in the background; poll get_database until the status settles. Data on the servers is kept unless erase_data is true.')]
#[IsDestructive]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Write)]
class DeleteDatabaseTool extends McpTool
{
    public function respond(Request $request, Database $database, DeleteDatabase $delete): Response
    {
        $delete->handle($database, $request->boolean('erase_data'), $request->boolean('force'), $request->boolean('delete_backups'));

        return $this->json(new DatabaseResource($database->refresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
            'erase_data' => $schema->boolean()->description('Also erase the data directories on the servers.'),
            'delete_backups' => $schema->boolean()->description('Also delete the backups in object storage.'),
            'force' => $schema->boolean()->description('Delete even if some servers cannot be reached.'),
        ];
    }
}
