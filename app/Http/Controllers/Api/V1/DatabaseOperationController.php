<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Databases\Configuration\RetuneDatabase;
use App\Actions\Databases\Failover\FailoverDatabase;
use App\Actions\Databases\Failover\RecoverDatabase;
use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Actions\Databases\Lifecycle\RetryDatabaseSetup;
use App\Actions\Databases\Lifecycle\StopOperation;
use App\Actions\Databases\Nodes\RebootDatabase;
use App\Actions\Databases\Upgrades\CheckDatabaseUpgrade;
use App\Actions\Databases\Upgrades\UpgradeDatabase;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\DatabaseResource;
use App\Models\Database;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DatabaseOperationController extends Controller
{
    public function failover(Database $database, FailoverDatabase $failover): JsonResponse
    {
        $failover->handle($database);

        return DatabaseResource::accepted($database);
    }

    public function refresh(Database $database, RefreshDatabaseStatus $refresh): DatabaseResource
    {
        $refresh->handle($database);

        return new DatabaseResource($database->refresh());
    }

    public function checkUpgrade(Database $database, CheckDatabaseUpgrade $check): DatabaseResource
    {
        $check->handle($database);

        return new DatabaseResource($database->refresh());
    }

    public function upgrade(Database $database, UpgradeDatabase $upgrade): JsonResponse
    {
        $upgrade->handle($database);

        return DatabaseResource::accepted($database);
    }

    public function recover(Request $request, Database $database, RecoverDatabase $recover): JsonResponse
    {
        $recover->handle($database, $request->boolean('others_down'));

        return DatabaseResource::accepted($database);
    }

    public function retune(Database $database, RetuneDatabase $retune): JsonResponse
    {
        $retune->handle($database);

        return DatabaseResource::accepted($database);
    }

    public function reboot(Database $database, RebootDatabase $reboot): JsonResponse
    {
        $reboot->handle($database);

        return DatabaseResource::accepted($database);
    }

    public function retrySetup(Database $database, RetryDatabaseSetup $retry): JsonResponse
    {
        $retry->handle($database);

        return DatabaseResource::accepted($database);
    }

    public function stop(Database $database, StopOperation $stop): DatabaseResource
    {
        $stop->handle($database);

        return new DatabaseResource($database->refresh());
    }
}
