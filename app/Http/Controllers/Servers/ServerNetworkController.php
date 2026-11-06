<?php

declare(strict_types=1);

namespace App\Http\Controllers\Servers;

use App\Actions\Servers\UpdateServerNetwork;
use App\Http\Controllers\Controller;
use App\Http\Requests\Servers\UpdateServerNetworkRequest;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;

class ServerNetworkController extends Controller
{
    public function update(UpdateServerNetworkRequest $request, Server $server, UpdateServerNetwork $updateNetwork): RedirectResponse
    {
        $updateNetwork->handle($server, $request->toPrivateNetwork());

        return back();
    }
}
