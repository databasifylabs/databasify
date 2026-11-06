<?php

declare(strict_types=1);

namespace App\Http\Controllers\Servers;

use App\Actions\Servers\AddServer;
use App\Actions\Servers\DeleteServer;
use App\DTOs\Servers\ServerFilters;
use App\Enums\Servers\OperatingSystem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Servers\StoreServerRequest;
use App\Http\Resources\NetworkResource;
use App\Http\Resources\ServerLogListResource;
use App\Http\Resources\ServerLogResource;
use App\Http\Resources\ServerProviderResource;
use App\Http\Resources\ServerResource;
use App\Http\Resources\ServerUserResource;
use App\Models\Server;
use App\Queries\Networks\NetworkQuery;
use App\Queries\Providers\ServerProviderQuery;
use App\Queries\Servers\ServerLogQuery;
use App\Queries\Servers\ServerQuery;
use App\Queries\Servers\ServerSummaryQuery;
use App\Queries\Servers\ServerUserQuery;
use App\Services\Ssh\AppSshKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ServerController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = ServerFilters::fromRequest($request);

        return Inertia::render('servers/index', [
            'servers' => ServerResource::collection(ServerQuery::filteredBy($filters)->cursorPaginate()),
            'summary' => ServerSummaryQuery::current()->get()->toArray(),
            'filters' => $filters->toArray(),
            'filterOptions' => ServerFilters::options(),
        ]);
    }

    public function create(AppSshKey $appKey): Response
    {
        return Inertia::render('servers/create', [
            'publicKey' => $appKey->get()->public_key,
            'networks' => $this->networks(),
            'providers' => ServerProviderResource::collection(ServerProviderQuery::all()->get())->resolve(),
            'operatingSystems' => array_map(fn (OperatingSystem $system): array => $system->toArray(), OperatingSystem::cases()),
        ]);
    }

    public function store(StoreServerRequest $request, AddServer $addServer): RedirectResponse
    {
        return to_route('servers.show', $addServer->handle($request->toServer()));
    }

    public function show(Request $request, Server $server): Response
    {
        return Inertia::render('servers/show', [
            'server' => new ServerResource($server->load(['databaseNodes.database', 'network', 'serverProvider'])),
            'networks' => $this->networks(),
            'users' => ServerUserResource::collection(ServerUserQuery::of($server)->get())->resolve(),
            'logs' => ServerLogListResource::collection(ServerLogQuery::of($server)->cursorPaginate()),
            'latestLogId' => fn () => ServerLogQuery::of($server)->latestId(),
            'log' => fn () => ServerLogQuery::of($server)->selected($request->string('log')->value())?->toResource(ServerLogResource::class)->resolve(),
        ]);
    }

    public function destroy(Request $request, Server $server, DeleteServer $deleteServer): RedirectResponse
    {
        $deleteServer->handle($server, $request->boolean('destroy_at_provider'));

        return to_route('servers.index');
    }

    /**
     * @return array<mixed>
     */
    private function networks(): array
    {
        return NetworkResource::collection(NetworkQuery::all()->get())->resolve();
    }
}
