<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Servers\UpdateServerNetwork;
use App\Http\Controllers\Controller;
use App\Http\Requests\Servers\UpdateServerNetworkRequest;
use App\Http\Resources\Api\V1\ServerResource;
use App\Models\Server;

class ServerNetworkController extends Controller
{
    public function __invoke(UpdateServerNetworkRequest $request, Server $server, UpdateServerNetwork $update): ServerResource
    {
        $update->handle($server, $request->toPrivateNetwork());

        return new ServerResource($server->refresh());
    }
}
