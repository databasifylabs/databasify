<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Databases\Upgrades\FindUnsupportedSdkDatabases;
use App\DTOs\Dashboard\SystemRequirements;
use App\Http\Resources\DatabaseListResource;
use App\Http\Resources\ServerLogListResource;
use App\Http\Resources\ServerResource;
use App\Models\Database;
use App\Queries\Dashboard\DashboardSummaryQuery;
use App\Queries\Dashboard\StarPromptQuery;
use App\Queries\Databases\DatabaseQuery;
use App\Queries\Servers\ServerLogQuery;
use App\Queries\Servers\ServerQuery;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    private const int PREVIEW = 10;

    public function __invoke(FindUnsupportedSdkDatabases $unsupportedSdk): Response
    {
        return Inertia::render('dashboard', [
            'summary' => DashboardSummaryQuery::current()->get()->toArray(),
            'databases' => DatabaseListResource::collection(DatabaseQuery::forList()->take(self::PREVIEW)),
            'servers' => ServerResource::collection(ServerQuery::all()->take(self::PREVIEW)),
            'logs' => ServerLogListResource::collection(ServerLogQuery::latest()->take(self::PREVIEW)),
            'requirements' => SystemRequirements::current()->toArray(),
            'showStarPrompt' => StarPromptQuery::current()->shows(),
            'sdkUnsupported' => $unsupportedSdk->handle()->map(fn (Database $database): array => ['id' => $database->id, 'name' => $database->name, 'sdk' => $database->imageSdk()])->values()->all(),
        ]);
    }
}
