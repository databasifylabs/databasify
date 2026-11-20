<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Databases\Pools\CreateConnectionPool;
use App\Actions\Databases\Pools\CreateNodeConnectionPool;
use App\Actions\Databases\Pools\DeleteConnectionPool;
use App\Actions\Databases\Pools\DeleteNodeConnectionPool;
use App\Http\Controllers\Controller;
use App\Http\Requests\Databases\StoreConnectionPoolRequest;
use App\Http\Resources\Api\V1\ConnectionPoolResource;
use App\Http\Resources\Api\V1\DatabaseNodeResource;
use App\Http\Resources\Api\V1\DatabaseResource;
use App\Models\ConnectionPool;
use App\Models\Database;
use App\Models\DatabaseNode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ConnectionPoolController extends Controller
{
    public function index(Database $database): AnonymousResourceCollection
    {
        return ConnectionPoolResource::collection($database->connectionPools);
    }

    public function store(StoreConnectionPoolRequest $request, Database $database, CreateConnectionPool $create): JsonResponse
    {
        $create->handle($database, $request->toPool());

        return DatabaseResource::accepted($database);
    }

    public function destroy(Database $database, ConnectionPool $connectionPool, DeleteConnectionPool $delete): JsonResponse
    {
        $delete->handle($connectionPool);

        return DatabaseResource::accepted($database);
    }

    public function nodeIndex(Database $database, DatabaseNode $node): AnonymousResourceCollection
    {
        return ConnectionPoolResource::collection($node->connectionPools);
    }

    public function nodeStore(StoreConnectionPoolRequest $request, Database $database, DatabaseNode $node, CreateNodeConnectionPool $create): JsonResponse
    {
        $create->handle($node, $request->toPool());

        return DatabaseNodeResource::accepted($node);
    }

    public function nodeDestroy(Database $database, DatabaseNode $node, ConnectionPool $connectionPool, DeleteNodeConnectionPool $delete): JsonResponse
    {
        $delete->handle($node, $connectionPool);

        return DatabaseNodeResource::accepted($node);
    }
}
