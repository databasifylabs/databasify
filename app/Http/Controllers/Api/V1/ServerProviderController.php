<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Providers\AddServerProvider;
use App\Actions\Providers\DeleteServerProvider;
use App\Actions\Providers\UpdateServerProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Providers\StoreServerProviderRequest;
use App\Http\Requests\Providers\UpdateServerProviderRequest;
use App\Http\Resources\Api\V1\ServerProviderResource;
use App\Models\ServerProvider;
use App\Queries\Providers\ServerProviderQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ServerProviderController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ServerProviderResource::collection(ServerProviderQuery::withUsage()->cursorPaginate());
    }

    public function types(): JsonResponse
    {
        return response()->json(['data' => ServerProvider::catalog()]);
    }

    public function store(StoreServerProviderRequest $request, AddServerProvider $add): JsonResponse
    {
        return (new ServerProviderResource($add->handle($request->toProvider())))->response()->setStatusCode(201);
    }

    public function show(ServerProvider $provider): ServerProviderResource
    {
        return new ServerProviderResource($provider->loadCount(['servers', 'networks']));
    }

    public function update(UpdateServerProviderRequest $request, ServerProvider $provider, UpdateServerProvider $update): ServerProviderResource
    {
        return new ServerProviderResource($update->handle($provider, $request->toProvider($provider)));
    }

    public function destroy(ServerProvider $provider, DeleteServerProvider $delete): Response
    {
        $delete->handle($provider);

        return response()->noContent();
    }
}
