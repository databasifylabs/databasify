<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\Dashboard\SystemRequirements;
use App\Http\Resources\DatabaseListResource;
use App\Http\Resources\ServerLogListResource;
use App\Http\Resources\ServerResource;
use App\Queries\Dashboard\DashboardSummaryQuery;
use App\Queries\Databases\DatabaseQuery;
use App\Queries\Servers\ServerLogQuery;
use App\Queries\Servers\ServerQuery;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    private const int PREVIEW = 10;

    public function __invoke(): Response
    {
        return Inertia::render('dashboard', [
            'summary' => DashboardSummaryQuery::current()->get()->toArray(),
            'databases' => DatabaseListResource::collection(DatabaseQuery::forList()->take(self::PREVIEW)),
            'servers' => ServerResource::collection(ServerQuery::all()->take(self::PREVIEW)),
            'logs' => ServerLogListResource::collection(ServerLogQuery::latest()->take(self::PREVIEW)),
            'requirements' => SystemRequirements::current()->toArray(),
        ]);
    }
}
