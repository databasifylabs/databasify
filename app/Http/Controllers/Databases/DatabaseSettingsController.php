<?php

declare(strict_types=1);

namespace App\Http\Controllers\Databases;

use App\Actions\Databases\Configuration\UpdateDatabaseConfig;
use App\Actions\Databases\Configuration\UpdatePersistence;
use App\Actions\Databases\Domains\AssignDatabaseZone;
use App\Http\Controllers\Controller;
use App\Http\Requests\Databases\UpdateDatabaseConfigRequest;
use App\Http\Requests\Databases\UpdateDatabaseDomainRequest;
use App\Http\Requests\Databases\UpdateMaintenanceWindowRequest;
use App\Http\Requests\Databases\UpdatePersistenceRequest;
use App\Models\Database;
use Illuminate\Http\RedirectResponse;

class DatabaseSettingsController extends Controller
{
    public function domain(UpdateDatabaseDomainRequest $request, Database $database, AssignDatabaseZone $assignZone): RedirectResponse
    {
        $assignZone->handle($database, $request->zone());

        return back();
    }

    public function config(UpdateDatabaseConfigRequest $request, Database $database, UpdateDatabaseConfig $updateConfig): RedirectResponse
    {
        $updateConfig->handle($database, $request->config());

        return back();
    }

    public function persistence(UpdatePersistenceRequest $request, Database $database, UpdatePersistence $updatePersistence): RedirectResponse
    {
        $updatePersistence->handle($database, $request->persistence());

        return back();
    }

    public function maintenance(UpdateMaintenanceWindowRequest $request, Database $database): RedirectResponse
    {
        $database->moveMaintenanceWindowTo($request->toWindow());

        return back();
    }
}
