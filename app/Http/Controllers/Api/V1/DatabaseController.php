<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Databases\Lifecycle\CreateDatabase;
use App\Actions\Databases\Lifecycle\DeleteDatabase;
use App\DTOs\Databases\DatabaseFilters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Databases\StoreDatabaseRequest;
use App\Http\Resources\Api\V1\DatabaseResource;
use App\Models\Database;
use App\Queries\Databases\DatabaseQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DatabaseController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return DatabaseResource::collection(DatabaseQuery::forList(DatabaseFilters::fromRequest($request))->cursorPaginate());
    }

    public function engines(): JsonResponse
    {
        return response()->json(['data' => Database::catalog()]);
    }

    public function store(StoreDatabaseRequest $request, CreateDatabase $create): JsonResponse
    {
        return (new DatabaseResource($create->handle($request->toDatabase())->refresh()))->response()->setStatusCode(202);
    }

    public function show(Database $database): DatabaseResource
    {
        return new DatabaseResource($database);
    }

    public function destroy(Request $request, Database $database, DeleteDatabase $delete): JsonResponse
    {
        $delete->handle($database, $request->boolean('erase_data'), $request->boolean('force'), $request->boolean('delete_backups'));

        return DatabaseResource::accepted($database);
    }
}
