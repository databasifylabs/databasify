<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Migrations\CheckMigrationSource;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Requests\Databases\MigrationSourceRequest;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('check_migration')]
#[Description('Connect to a source database and report its version, size, databases and whether it can be replicated.')]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Write)]
class CheckMigrationTool extends McpTool
{
    public function respond(MigrationSourceRequest $request, Database $database, CheckMigrationSource $check): Response
    {
        return Response::json($check->handle($database, $request->source())->toArray());
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
        ];
    }
}
