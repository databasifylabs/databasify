<?php

declare(strict_types=1);

namespace App\Http\Controllers\Databases;

use App\Actions\Databases\Pools\CreateConnectionPool;
use App\Actions\Databases\Pools\DeleteConnectionPool;
use App\Http\Controllers\Controller;
use App\Http\Requests\Databases\StoreConnectionPoolRequest;
use App\Models\ConnectionPool;
use App\Models\Database;
use Illuminate\Http\RedirectResponse;

class ConnectionPoolController extends Controller
{
    public function store(StoreConnectionPoolRequest $request, Database $database, CreateConnectionPool $createPool): RedirectResponse
    {
        $createPool->handle($database, $request->toPool());

        return back();
    }

    public function destroy(Database $database, ConnectionPool $connectionPool, DeleteConnectionPool $deletePool): RedirectResponse
    {
        $deletePool->handle($connectionPool);

        return back();
    }
}
