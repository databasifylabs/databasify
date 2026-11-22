<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Monitoring\ReadNodeLogs;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Requests\Databases\ReadNodeLogsRequest;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use App\Models\DatabaseNode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_node_logs')]
#[Description('Read the latest lines of an engine service log on a node, such as postgres or patroni.')]
#[IsReadOnly]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Read)]
class GetNodeLogsTool extends McpTool
{
    public function respond(ReadNodeLogsRequest $request, Database $database, DatabaseNode $node, ReadNodeLogs $readLogs): Response
    {
        return Response::json(['logs' => $readLogs->handle($node, $request->service(), $request->lines())]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
            'node' => $this->uuid($schema, 'The node id from list_database_nodes.')->required(),
            'service' => $schema->string()->description('The service name, from the log units of get_database.')->required(),
            'lines' => $schema->integer()->description('How many lines, 10 to 2000.'),
        ];
    }
}
