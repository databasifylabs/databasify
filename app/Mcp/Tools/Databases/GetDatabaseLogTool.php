<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Resources\Api\V1\ServerLogOutputResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use App\Queries\Servers\ServerLogQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_database_log')]
#[Description('Get one database log with its full output.')]
#[IsReadOnly]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Read)]
class GetDatabaseLogTool extends McpTool
{
    public function respond(Request $request, Database $database): Response
    {
        $log = ServerLogQuery::of($database)->selected($request->string('log')->value());

        return $this->json(new ServerLogOutputResource($log ?? throw ValidationException::withMessages(['log' => 'No log with this id.'])));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
            'log' => $this->uuid($schema, 'The log id from list_database_logs.')->required(),
        ];
    }
}
