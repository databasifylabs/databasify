<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Servers\QueueProvisioning;
use App\Actions\Servers\ReadServerFacts;
use App\Actions\Servers\RunServerOperation;
use App\Enums\Servers\ServerOperation;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ServerResource;
use App\Models\Server;
use Illuminate\Http\JsonResponse;

class ServerOperationController extends Controller
{
    public function run(Server $server, ServerOperation $operation, RunServerOperation $run): JsonResponse
    {
        $run->handle($server, $operation);

        return (new ServerResource($server->refresh()))->response()->setStatusCode(202);
    }

    public function provision(Server $server, QueueProvisioning $provisioning): JsonResponse
    {
        $provisioning->handle($server);

        return (new ServerResource($server->refresh()))->response()->setStatusCode(202);
    }

    public function refresh(Server $server, ReadServerFacts $refresh): ServerResource
    {
        $refresh->handle($server);

        return new ServerResource($server->refresh());
    }
}
