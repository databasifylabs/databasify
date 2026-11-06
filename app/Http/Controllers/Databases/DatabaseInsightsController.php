<?php

declare(strict_types=1);

namespace App\Http\Controllers\Databases;

use App\Actions\Databases\Monitoring\ReadMetricHistory;
use App\Actions\Databases\Monitoring\ReadNodeLogs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Databases\ReadMetricHistoryRequest;
use App\Http\Requests\Databases\ReadNodeLogsRequest;
use App\Models\Database;
use App\Models\DatabaseNode;
use Illuminate\Http\JsonResponse;

class DatabaseInsightsController extends Controller
{
    public function history(ReadMetricHistoryRequest $request, Database $database, ReadMetricHistory $readHistory): JsonResponse
    {
        return response()->json($readHistory->handle($database, $request->period())->toArray());
    }

    public function nodeHistory(ReadMetricHistoryRequest $request, Database $database, DatabaseNode $node, ReadMetricHistory $readHistory): JsonResponse
    {
        return response()->json($readHistory->handle($database, $request->period(), $node)->toArray());
    }

    public function logs(ReadNodeLogsRequest $request, Database $database, DatabaseNode $node, ReadNodeLogs $readLogs): JsonResponse
    {
        return response()->json(['logs' => $readLogs->handle($node, $request->service(), $request->lines())]);
    }
}
