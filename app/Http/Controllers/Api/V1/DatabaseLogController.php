<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ServerLogOutputResource;
use App\Http\Resources\Api\V1\ServerLogResource;
use App\Models\Database;
use App\Queries\Servers\ServerLogQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DatabaseLogController extends Controller
{
    public function index(Database $database): AnonymousResourceCollection
    {
        return ServerLogResource::collection(ServerLogQuery::of($database)->cursorPaginate());
    }

    public function show(Database $database, string $log): ServerLogOutputResource
    {
        return new ServerLogOutputResource(ServerLogQuery::of($database)->selected($log) ?? abort(404));
    }
}
