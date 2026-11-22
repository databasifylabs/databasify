<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Migrations\StartMigration;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Enums\Migrations\MigrationMode;
use App\Http\Requests\Databases\MigrationSourceRequest;
use App\Http\Resources\Api\V1\DatabaseResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('start_migration')]
#[Description('Start copying a source database into this one, then keep it in sync until finish_migration. It runs in the background; poll get_database until the status settles.')]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Write)]
class StartMigrationTool extends McpTool
{
    public function respond(MigrationSourceRequest $request, Database $database, StartMigration $startMigration): Response
    {
        $startMigration->handle($database, $request->source(), $request->mode());

        return $this->json(new DatabaseResource($database->refresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
            'host' => $schema->string()->description('The source server host name or IP.')->required(),
            'port' => $schema->integer()->description('The source port.')->required(),
            'username' => $schema->string()->description('The source user, if the engine needs credentials.'),
            'password' => $schema->string()->description('The source password. Never returned.'),
            'databases' => $schema->array()->items($schema->string())->description('Only copy these databases, where the engine supports it.'),
            'tls' => $schema->boolean()->description('Connect to the source over TLS.'),
            'mode' => $schema->string()->enum(array_column(MigrationMode::cases(), 'value'))->description('How to migrate. Omit for the engine default.'),
        ];
    }
}
