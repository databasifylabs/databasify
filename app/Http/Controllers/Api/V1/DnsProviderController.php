<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Dns\AddDnsProvider;
use App\Actions\Dns\DeleteDnsProvider;
use App\Actions\Dns\ImportDnsZones;
use App\Actions\Dns\UpdateDnsProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dns\StoreDnsProviderRequest;
use App\Http\Requests\Dns\UpdateDnsProviderRequest;
use App\Http\Resources\Api\V1\DnsProviderResource;
use App\Models\DnsProvider;
use App\Queries\Dns\DnsProviderQuery;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class DnsProviderController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return DnsProviderResource::collection(DnsProviderQuery::withZones()->cursorPaginate());
    }

    public function types(): JsonResponse
    {
        return response()->json(['data' => DnsProvider::catalog()]);
    }

    public function store(StoreDnsProviderRequest $request, AddDnsProvider $add): JsonResponse
    {
        return $this->resourceOf($add->handle($request->toProvider()))->response()->setStatusCode(201);
    }

    public function show(DnsProvider $provider): DnsProviderResource
    {
        return $this->resourceOf($provider);
    }

    public function update(UpdateDnsProviderRequest $request, DnsProvider $provider, UpdateDnsProvider $update): DnsProviderResource
    {
        return $this->resourceOf($update->handle($provider, $request->toProvider($provider)));
    }

    public function refresh(DnsProvider $provider, ImportDnsZones $import): DnsProviderResource
    {
        $import->handle($provider);

        return $this->resourceOf($provider);
    }

    public function destroy(DnsProvider $provider, DeleteDnsProvider $delete): Response
    {
        $delete->handle($provider);

        return response()->noContent();
    }

    private function resourceOf(DnsProvider $provider): DnsProviderResource
    {
        return new DnsProviderResource($provider->load(['zones' => fn (HasMany $zones) => $zones->withCount('databases')]));
    }
}
