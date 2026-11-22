<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Monitoring\ReadMetricHistory;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Enums\Databases\MetricPeriod;
use App\Http\Requests\Databases\ReadMetricHistoryRequest;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use App\Models\DatabaseNode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_database_metrics')]
#[Description('Read the metric history (CPU, memory, disk, connections, replication lag) of a database or one of its nodes.')]
#[IsReadOnly]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Read)]
class GetDatabaseMetricsTool extends McpTool
{
    public function respond(ReadMetricHistoryRequest $request, Database $database, ?DatabaseNode $node, ReadMetricHistory $readHistory): Response
    {
        return Response::json($readHistory->handle($database, $request->period(), $node)->toArray());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
            'node' => $this->uuid($schema, 'Only this node.'),
            'period' => $schema->string()->enum(array_column(MetricPeriod::cases(), 'value'))->description('The time window, 1h by default.'),
        ];
    }
}
