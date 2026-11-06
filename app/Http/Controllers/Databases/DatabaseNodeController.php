<?php

declare(strict_types=1);

namespace App\Http\Controllers\Databases;

use App\Actions\Databases\Nodes\AddDatabaseNode;
use App\Actions\Databases\Nodes\RemoveDatabaseNode;
use App\Actions\Databases\Nodes\RenameDatabaseNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Databases\AddDatabaseNodeRequest;
use App\Http\Requests\Databases\UpdateDatabaseNodeRequest;
use App\Http\Resources\DatabaseNodeResource;
use App\Http\Resources\DatabaseResource;
use App\Http\Resources\ServerLogListResource;
use App\Http\Resources\ServerLogResource;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Queries\Servers\ServerLogQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DatabaseNodeController extends Controller
{
    public function show(Request $request, Database $database, DatabaseNode $node): Response|RedirectResponse
    {
        if ($node->isMember()) {
            return to_route('databases.show', $database);
        }

        $endpoints = $node->endpoints();

        return Inertia::render('databases/nodes/show', [
            'clientIp' => $request->ip(),
            'database' => new DatabaseResource($database->load(DatabaseResource::RELATIONS)),
            'node' => new DatabaseNodeResource($node->load('server')),
            'endpoints' => $endpoints->toArray(),
            'appEnv' => $database->provider()->environment($endpoints),
            'logs' => ServerLogListResource::collection(ServerLogQuery::of($database)->cursorPaginate()),
            'latestLogId' => fn () => ServerLogQuery::of($database)->latestId(),
            'log' => fn () => ServerLogQuery::of($database)->selected($request->string('log')->value())?->toResource(ServerLogResource::class)->resolve(),
        ]);
    }

    public function store(AddDatabaseNodeRequest $request, Database $database, AddDatabaseNode $addNode): RedirectResponse
    {
        $addNode->handle($database, $request->toNode());

        return back();
    }

    public function update(UpdateDatabaseNodeRequest $request, Database $database, DatabaseNode $node, RenameDatabaseNode $renameNode): RedirectResponse
    {
        $renameNode->handle($node, $request->nodeName());

        return back();
    }

    public function destroy(Request $request, Database $database, DatabaseNode $node, RemoveDatabaseNode $removeNode): RedirectResponse
    {
        $removeNode->handle($node, $request->boolean('force'));

        return to_route('databases.show', $database);
    }
}
