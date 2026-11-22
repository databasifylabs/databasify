<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Servers;

use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Resources\Api\V1\ServerLogOutputResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Server;
use App\Queries\Servers\ServerLogQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_server_log')]
#[Description('Get one server log with its full output.')]
#[IsReadOnly]
#[RequiresAccess(AccessArea::Servers, AccessLevel::Read)]
class GetServerLogTool extends McpTool
{
    public function respond(Request $request, Server $server): Response
    {
        $log = ServerLogQuery::of($server)->selected($request->string('log')->value());

        return $this->json(new ServerLogOutputResource($log ?? throw ValidationException::withMessages(['log' => 'No log with this id.'])));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'server' => $this->uuid($schema, 'The server id.')->required(),
            'log' => $this->uuid($schema, 'The log id from list_server_logs.')->required(),
        ];
    }
}
