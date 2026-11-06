<?php

declare(strict_types=1);

namespace App\Http\Controllers\Providers;

use App\Actions\Providers\AddServerProvider;
use App\Actions\Providers\DeleteServerProvider;
use App\Actions\Providers\UpdateServerProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Providers\StoreServerProviderRequest;
use App\Http\Requests\Providers\UpdateServerProviderRequest;
use App\Http\Resources\NetworkResource;
use App\Http\Resources\ServerProviderResource;
use App\Models\ServerProvider;
use App\Queries\Networks\NetworkQuery;
use App\Queries\Providers\ServerProviderQuery;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ServerProviderController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('providers/index', [
            'providers' => ServerProviderResource::collection(ServerProviderQuery::withUsage()->get())->resolve(),
            'networks' => NetworkResource::collection(NetworkQuery::withUsage()->get())->resolve(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('providers/create', [
            'types' => ServerProvider::catalog(),
        ]);
    }

    public function store(StoreServerProviderRequest $request, AddServerProvider $addProvider): RedirectResponse
    {
        $addProvider->handle($request->toProvider());

        return to_route('providers.index');
    }

    public function update(UpdateServerProviderRequest $request, ServerProvider $provider, UpdateServerProvider $update): RedirectResponse
    {
        $update->handle($provider, $request->toProvider($provider));

        return back();
    }

    public function destroy(ServerProvider $provider, DeleteServerProvider $deleteProvider): RedirectResponse
    {
        $deleteProvider->handle($provider);

        return back();
    }
}
