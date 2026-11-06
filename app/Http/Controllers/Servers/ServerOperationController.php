<?php

declare(strict_types=1);

namespace App\Http\Controllers\Servers;

use App\Actions\Servers\QueueProvisioning;
use App\Actions\Servers\QueueServerFactsRefresh;
use App\Actions\Servers\RunServerOperation;
use App\Enums\Servers\ServerOperation;
use App\Http\Controllers\Controller;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;

class ServerOperationController extends Controller
{
    public function store(Server $server, ServerOperation $operation, RunServerOperation $run): RedirectResponse
    {
        $run->handle($server, $operation);

        return back();
    }

    public function provision(Server $server, QueueProvisioning $provisioning): RedirectResponse
    {
        $provisioning->handle($server);

        return back();
    }

    public function refresh(Server $server, QueueServerFactsRefresh $refresh): RedirectResponse
    {
        $refresh->handle($server);

        return back();
    }
}
