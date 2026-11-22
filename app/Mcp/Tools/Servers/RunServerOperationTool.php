<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Servers;

use App\Actions\Servers\RunServerOperation;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Enums\Servers\ServerOperation;
use App\Http\Resources\Api\V1\ServerResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Server;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('run_server_operation')]
#[Description('Run an operation on a ready server: update-packages or reboot. It runs in the background; poll get_server.')]
#[IsDestructive]
#[RequiresAccess(AccessArea::Servers, AccessLevel::Write)]
class RunServerOperationTool extends McpTool
{
    public function respond(Request $request, Server $server, RunServerOperation $run): Response
    {
        $run->handle($server, $request->enum('operation', ServerOperation::class) ?? throw ValidationException::withMessages(['operation' => 'The selected operation is invalid.']));

        return $this->json(new ServerResource($server->refresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'server' => $this->uuid($schema, 'The server id.')->required(),
            'operation' => $schema->string()->enum(array_column(ServerOperation::cases(), 'value'))->description('The operation to run.')->required(),
        ];
    }
}
