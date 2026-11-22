<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Storage\AddStorageProvider;
use App\Actions\Storage\DeleteStorageProvider;
use App\Actions\Storage\TestStorageProvider;
use App\Actions\Storage\UpdateStorageProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Storage\StoreStorageProviderRequest;
use App\Http\Requests\Storage\UpdateStorageProviderRequest;
use App\Http\Resources\Api\V1\StorageProviderResource;
use App\Models\StorageProvider;
use App\Queries\Storage\StorageProviderQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class StorageProviderController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return StorageProviderResource::collection(StorageProviderQuery::withRecoveries()->cursorPaginate());
    }

    public function store(StoreStorageProviderRequest $request, AddStorageProvider $add): JsonResponse
    {
        return StorageProviderResource::loaded($add->handle($request->toProvider()))->response()->setStatusCode(201);
    }

    public function show(StorageProvider $provider): StorageProviderResource
    {
        return StorageProviderResource::loaded($provider);
    }

    public function update(UpdateStorageProviderRequest $request, StorageProvider $provider, UpdateStorageProvider $update): StorageProviderResource
    {
        return StorageProviderResource::loaded($update->handle($provider, $request->toProvider($provider)));
    }

    public function test(StorageProvider $provider, TestStorageProvider $test): StorageProviderResource
    {
        $test->handle($provider);

        return StorageProviderResource::loaded($provider);
    }

    public function destroy(StorageProvider $provider, DeleteStorageProvider $delete): Response
    {
        $delete->handle($provider);

        return response()->noContent();
    }
}
