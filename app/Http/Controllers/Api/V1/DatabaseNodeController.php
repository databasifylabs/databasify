<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Databases\Configuration\RetuneNode;
use App\Actions\Databases\Configuration\UpdateNodeConfig;
use App\Actions\Databases\Lifecycle\StopOperation;
use App\Actions\Databases\Nodes\AddDatabaseNode;
use App\Actions\Databases\Nodes\RebootNode;
use App\Actions\Databases\Nodes\RemoveDatabaseNode;
use App\Actions\Databases\Nodes\RenameDatabaseNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Databases\AddDatabaseNodeRequest;
use App\Http\Requests\Databases\UpdateDatabaseConfigRequest;
use App\Http\Requests\Databases\UpdateDatabaseNodeRequest;
use App\Http\Resources\Api\V1\DatabaseNodeResource;
use App\Http\Resources\Api\V1\DatabaseResource;
use App\Models\Database;
use App\Models\DatabaseNode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DatabaseNodeController extends Controller
{
    public function index(Database $database): AnonymousResourceCollection
    {
        return DatabaseNodeResource::collection($database->nodes);
    }

    public function store(AddDatabaseNodeRequest $request, Database $database, AddDatabaseNode $add): JsonResponse
    {
        $add->handle($database, $request->toNode());

        return DatabaseResource::accepted($database);
    }

    public function show(Database $database, DatabaseNode $node): DatabaseNodeResource
    {
        return new DatabaseNodeResource($node);
    }

    public function update(UpdateDatabaseNodeRequest $request, Database $database, DatabaseNode $node, RenameDatabaseNode $rename): JsonResponse
    {
        $rename->handle($node, $request->nodeName());

        return DatabaseNodeResource::accepted($node);
    }

    public function config(UpdateDatabaseConfigRequest $request, Database $database, DatabaseNode $node, UpdateNodeConfig $updateConfig): JsonResponse
    {
        $updateConfig->handle($node, $request->config());

        return DatabaseNodeResource::accepted($node);
    }

    public function reboot(Database $database, DatabaseNode $node, RebootNode $reboot): JsonResponse
    {
        $reboot->handle($node);

        return DatabaseNodeResource::accepted($node);
    }

    public function retune(Database $database, DatabaseNode $node, RetuneNode $retune): JsonResponse
    {
        $retune->handle($node);

        return DatabaseNodeResource::accepted($node);
    }

    public function stop(Database $database, DatabaseNode $node, StopOperation $stop): DatabaseNodeResource
    {
        $stop->handle($node);

        return new DatabaseNodeResource($node->refresh());
    }

    public function destroy(Request $request, Database $database, DatabaseNode $node, RemoveDatabaseNode $remove): JsonResponse
    {
        $remove->handle($node, $request->boolean('force'));

        return DatabaseResource::accepted($database);
    }
}
