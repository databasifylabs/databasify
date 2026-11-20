<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Servers\CreateServerUser;
use App\Actions\Servers\DeleteServerUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Servers\StoreServerUserRequest;
use App\Http\Resources\Api\V1\ServerUserResource;
use App\Models\Server;
use App\Models\ServerUser;
use App\Queries\Servers\ServerUserQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ServerUserController extends Controller
{
    public function index(Server $server): AnonymousResourceCollection
    {
        return ServerUserResource::collection(ServerUserQuery::of($server)->cursorPaginate());
    }

    public function store(StoreServerUserRequest $request, Server $server, CreateServerUser $create): JsonResponse
    {
        $create->handle($server, $request->username(), $request->publicKey());

        return (new ServerUserResource($server->users()->where('username', $request->username())->firstOrFail()))->response()->setStatusCode(201);
    }

    public function destroy(Server $server, ServerUser $user, DeleteServerUser $delete): Response
    {
        $delete->handle($user);

        return response()->noContent();
    }
}
