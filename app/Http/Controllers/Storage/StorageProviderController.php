<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storage;

use App\Actions\Storage\AddStorageProvider;
use App\Actions\Storage\DeleteStorageProvider;
use App\Actions\Storage\TestStorageProvider;
use App\Actions\Storage\UpdateStorageProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Storage\StoreStorageProviderRequest;
use App\Http\Requests\Storage\UpdateStorageProviderRequest;
use App\Http\Resources\StorageProviderResource;
use App\Models\StorageProvider;
use App\Queries\Storage\StorageProviderQuery;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class StorageProviderController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('storage/index', [
            'providers' => StorageProviderResource::collection(StorageProviderQuery::withRecoveries()->get())->resolve(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('storage/create');
    }

    public function store(StoreStorageProviderRequest $request, AddStorageProvider $add): RedirectResponse
    {
        $add->handle($request->toProvider());

        return to_route('storage-providers.index');
    }

    public function edit(StorageProvider $provider): Response
    {
        return Inertia::render('storage/edit', [
            'provider' => (new StorageProviderResource($provider->load('recoveries.database')))->resolve(),
        ]);
    }

    public function update(UpdateStorageProviderRequest $request, StorageProvider $provider, UpdateStorageProvider $update): RedirectResponse
    {
        $update->handle($provider, $request->toProvider($provider));

        return to_route('storage-providers.index');
    }

    public function test(StorageProvider $provider, TestStorageProvider $test): RedirectResponse
    {
        $test->handle($provider);

        return back();
    }

    public function destroy(StorageProvider $provider, DeleteStorageProvider $delete): RedirectResponse
    {
        $delete->handle($provider);

        return back();
    }
}
