<?php

declare(strict_types=1);

namespace App\Http\Controllers\Databases;

use App\Actions\Databases\Lifecycle\CreateDatabase;
use App\Actions\Databases\Lifecycle\DeleteDatabase;
use App\DTOs\Databases\DatabaseFilters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Databases\StoreDatabaseRequest;
use App\Http\Resources\DatabaseListResource;
use App\Http\Resources\DatabaseResource;
use App\Http\Resources\DnsZoneResource;
use App\Http\Resources\ServerLogListResource;
use App\Http\Resources\ServerLogResource;
use App\Http\Resources\ServerResource;
use App\Http\Resources\StorageProviderOptionResource;
use App\Models\Database;
use App\Queries\Databases\DatabaseQuery;
use App\Queries\Dns\DnsZoneQuery;
use App\Queries\Servers\AvailableServerQuery;
use App\Queries\Servers\ServerLogQuery;
use App\Queries\Storage\StorageProviderQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Inertia\Inertia;
use Inertia\Response;

class DatabaseController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = DatabaseFilters::fromRequest($request);

        return Inertia::render('databases/index', [
            'databases' => DatabaseListResource::collection(DatabaseQuery::forList($filters)->cursorPaginate()),
            'filters' => $filters->toArray(),
            'filterOptions' => DatabaseFilters::options(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('databases/create', [
            'engines' => Database::catalog(),
            'servers' => $this->freeServers(),
        ]);
    }

    public function store(StoreDatabaseRequest $request, CreateDatabase $createDatabase): RedirectResponse
    {
        return to_route('databases.show', $createDatabase->handle($request->toDatabase()));
    }

    public function show(Request $request, Database $database): Response
    {
        return Inertia::render('databases/show', [
            'clientIp' => $request->ip(),
            'database' => new DatabaseResource($database->load(DatabaseResource::RELATIONS)),
            'appEnv' => $database->environment($database->endpoints()),
            'sdkUnsupported' => $database->unsupportedSdk()?->toArray(),
            'availableServers' => $this->freeServers(),
            'dnsZones' => $this->dnsZones(),
            'storageProviders' => $this->storageProviders(),
            'logs' => ServerLogListResource::collection(ServerLogQuery::of($database)->cursorPaginate()),
            'latestLogId' => fn () => ServerLogQuery::of($database)->latestId(),
            'log' => fn () => ServerLogQuery::of($database)->selected($request->string('log')->value())?->toResource(ServerLogResource::class)->resolve(),
        ]);
    }

    public function destroy(Request $request, Database $database, DeleteDatabase $deleteDatabase): RedirectResponse
    {
        $deleteDatabase->handle($database, $request->boolean('erase_data'), $request->boolean('force'), $request->boolean('delete_backups'));

        return to_route('databases.index');
    }

    /**
     * @return AnonymousResourceCollection<int, ServerResource>
     */
    private function freeServers(): AnonymousResourceCollection
    {
        return ServerResource::collection(AvailableServerQuery::all()->get());
    }

    /**
     * @return array<mixed>
     */
    private function storageProviders(): array
    {
        return StorageProviderOptionResource::collection(StorageProviderQuery::all()->get())->resolve();
    }

    /**
     * @return array<mixed>
     */
    private function dnsZones(): array
    {
        return DnsZoneResource::collection(DnsZoneQuery::all()->get())->resolve();
    }
}
