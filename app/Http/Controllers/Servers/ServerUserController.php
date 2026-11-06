<?php

declare(strict_types=1);

namespace App\Http\Controllers\Servers;

use App\Actions\Servers\CreateServerUser;
use App\Actions\Servers\DeleteServerUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Servers\StoreServerUserRequest;
use App\Models\Server;
use App\Models\ServerUser;
use Illuminate\Http\RedirectResponse;

class ServerUserController extends Controller
{
    public function store(StoreServerUserRequest $request, Server $server, CreateServerUser $createUser): RedirectResponse
    {
        $createUser->handle($server, $request->username(), $request->publicKey());

        return back();
    }

    public function destroy(Server $server, ServerUser $user, DeleteServerUser $deleteUser): RedirectResponse
    {
        $deleteUser->handle($user);

        return back();
    }
}
