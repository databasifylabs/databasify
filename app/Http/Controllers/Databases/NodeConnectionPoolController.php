<?php

declare(strict_types=1);

namespace App\Http\Controllers\Databases;

use App\Actions\Databases\Pools\CreateNodeConnectionPool;
use App\Actions\Databases\Pools\DeleteNodeConnectionPool;
use App\Http\Controllers\Controller;
use App\Http\Requests\Databases\StoreConnectionPoolRequest;
use App\Models\ConnectionPool;
use App\Models\Database;
use App\Models\DatabaseNode;
use Illuminate\Http\RedirectResponse;

final class NodeConnectionPoolController extends Controller
{
    public function store(StoreConnectionPoolRequest $request, Database $database, DatabaseNode $node, CreateNodeConnectionPool $createPool): RedirectResponse
    {
        $createPool->handle($node, $request->toPool());

        return back();
    }

    public function destroy(Database $database, DatabaseNode $node, ConnectionPool $connectionPool, DeleteNodeConnectionPool $deletePool): RedirectResponse
    {
        $deletePool->handle($node, $connectionPool);

        return back();
    }
}
