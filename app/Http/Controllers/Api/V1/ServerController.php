<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Servers\AddServer;
use App\Actions\Servers\DeleteServer;
use App\DTOs\Servers\ServerFilters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Servers\StoreServerRequest;
use App\Http\Resources\Api\V1\ServerResource;
use App\Models\Server;
use App\Queries\Servers\ServerQuery;
use App\Services\Ssh\AppSshKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ServerController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return ServerResource::collection(ServerQuery::filteredBy(ServerFilters::fromRequest($request))->cursorPaginate());
    }

    public function sshKey(AppSshKey $appKey): JsonResponse
    {
        return response()->json(['data' => ['public_key' => $appKey->get()->public_key]]);
    }

    public function store(StoreServerRequest $request, AddServer $add): JsonResponse
    {
        return (new ServerResource($add->handle($request->toServer())))->response()->setStatusCode(202);
    }

    public function show(Server $server): ServerResource
    {
        return new ServerResource($server->load('databaseNodes.database'));
    }

    public function destroy(Request $request, Server $server, DeleteServer $delete): Response
    {
        $delete->handle($server, $request->boolean('destroy_at_provider'));

        return response()->noContent();
    }
}
