<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Databases\Migrations\CheckMigrationSource;
use App\Actions\Databases\Migrations\StartMigration;
use App\Actions\Databases\Migrations\StopMigration;
use App\Http\Controllers\Controller;
use App\Http\Requests\Databases\MigrationSourceRequest;
use App\Http\Resources\Api\V1\DatabaseMigrationResource;
use App\Http\Resources\Api\V1\DatabaseResource;
use App\Models\Database;
use App\Models\DatabaseMigration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DatabaseMigrationController extends Controller
{
    public function index(Database $database): AnonymousResourceCollection
    {
        return DatabaseMigrationResource::collection($database->migrations);
    }

    public function check(MigrationSourceRequest $request, Database $database, CheckMigrationSource $check): JsonResponse
    {
        return response()->json(['data' => $check->handle($database, $request->source())->toArray()]);
    }

    public function store(MigrationSourceRequest $request, Database $database, StartMigration $start): JsonResponse
    {
        $start->handle($database, $request->source(), $request->mode());

        return DatabaseResource::accepted($database);
    }

    public function finish(Database $database, DatabaseMigration $migration, StopMigration $stop): JsonResponse
    {
        $stop->handle($migration, cutover: true);

        return DatabaseResource::accepted($database);
    }

    public function destroy(Request $request, Database $database, DatabaseMigration $migration, StopMigration $stop): JsonResponse
    {
        $stop->handle($migration, cutover: false, force: $request->boolean('force'));

        return DatabaseResource::accepted($database);
    }
}
