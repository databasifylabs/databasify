<?php

declare(strict_types=1);

namespace App\Http\Controllers\Databases;

use App\Actions\Databases\Migrations\CheckMigrationSource;
use App\Actions\Databases\Migrations\StartMigration;
use App\Actions\Databases\Migrations\StopMigration;
use App\Http\Controllers\Controller;
use App\Http\Requests\Databases\MigrationSourceRequest;
use App\Models\Database;
use App\Models\DatabaseMigration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DatabaseMigrationController extends Controller
{
    public function check(MigrationSourceRequest $request, Database $database, CheckMigrationSource $check): JsonResponse
    {
        return response()->json($check->handle($database, $request->source())->toArray());
    }

    public function store(MigrationSourceRequest $request, Database $database, StartMigration $start): RedirectResponse
    {
        $start->handle($database, $request->source(), $request->mode());

        return back();
    }

    public function finish(Database $database, DatabaseMigration $migration, StopMigration $stop): RedirectResponse
    {
        $stop->handle($migration, cutover: true);

        return back();
    }

    public function destroy(Request $request, Database $database, DatabaseMigration $migration, StopMigration $stop): RedirectResponse
    {
        $stop->handle($migration, cutover: false, force: $request->boolean('force'));

        return back();
    }
}
