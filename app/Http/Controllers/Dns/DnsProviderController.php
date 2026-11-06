<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dns;

use App\Actions\Dns\AddDnsProvider;
use App\Actions\Dns\DeleteDnsProvider;
use App\Actions\Dns\ImportDnsZones;
use App\Actions\Dns\UpdateDnsProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dns\StoreDnsProviderRequest;
use App\Http\Requests\Dns\UpdateDnsProviderRequest;
use App\Http\Resources\DnsProviderResource;
use App\Models\DnsProvider;
use App\Queries\Dns\DnsProviderQuery;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class DnsProviderController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('dns/index', [
            'providers' => DnsProviderResource::collection(DnsProviderQuery::withZones()->get())->resolve(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('dns/create', [
            'types' => DnsProvider::catalog(),
        ]);
    }

    public function store(StoreDnsProviderRequest $request, AddDnsProvider $add): RedirectResponse
    {
        $add->handle($request->toProvider());

        return to_route('dns.index');
    }

    public function update(UpdateDnsProviderRequest $request, DnsProvider $provider, UpdateDnsProvider $update): RedirectResponse
    {
        $update->handle($provider, $request->toProvider($provider));

        return back();
    }

    public function refresh(DnsProvider $provider, ImportDnsZones $import): RedirectResponse
    {
        $import->handle($provider);

        return back();
    }

    public function destroy(DnsProvider $provider, DeleteDnsProvider $delete): RedirectResponse
    {
        $delete->handle($provider);

        return back();
    }
}
