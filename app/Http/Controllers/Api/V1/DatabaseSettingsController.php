<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Databases\Configuration\UpdateDatabaseConfig;
use App\Actions\Databases\Configuration\UpdatePersistence;
use App\Actions\Databases\Domains\AssignDatabaseZone;
use App\Http\Controllers\Controller;
use App\Http\Requests\Databases\UpdateDatabaseConfigRequest;
use App\Http\Requests\Databases\UpdateDatabaseDomainRequest;
use App\Http\Requests\Databases\UpdateMaintenanceWindowRequest;
use App\Http\Requests\Databases\UpdatePersistenceRequest;
use App\Http\Resources\Api\V1\DatabaseResource;
use App\Models\Database;
use Illuminate\Http\JsonResponse;

class DatabaseSettingsController extends Controller
{
    public function domain(UpdateDatabaseDomainRequest $request, Database $database, AssignDatabaseZone $assignZone): JsonResponse
    {
        $assignZone->handle($database, $request->zone());

        return DatabaseResource::accepted($database);
    }

    public function config(UpdateDatabaseConfigRequest $request, Database $database, UpdateDatabaseConfig $updateConfig): JsonResponse
    {
        $updateConfig->handle($database, $request->config());

        return DatabaseResource::accepted($database);
    }

    public function persistence(UpdatePersistenceRequest $request, Database $database, UpdatePersistence $updatePersistence): JsonResponse
    {
        $updatePersistence->handle($database, $request->persistence());

        return DatabaseResource::accepted($database);
    }

    public function maintenance(UpdateMaintenanceWindowRequest $request, Database $database): DatabaseResource
    {
        $database->moveMaintenanceWindowTo($request->toWindow());

        return new DatabaseResource($database->refresh());
    }
}
