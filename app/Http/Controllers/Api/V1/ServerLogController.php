<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ServerLogOutputResource;
use App\Http\Resources\Api\V1\ServerLogResource;
use App\Models\Server;
use App\Queries\Servers\ServerLogQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ServerLogController extends Controller
{
    public function index(Server $server): AnonymousResourceCollection
    {
        return ServerLogResource::collection(ServerLogQuery::of($server)->cursorPaginate());
    }

    public function show(Server $server, string $log): ServerLogOutputResource
    {
        return new ServerLogOutputResource(ServerLogQuery::of($server)->selected($log) ?? abort(404));
    }
}
