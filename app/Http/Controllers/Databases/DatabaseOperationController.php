<?php

declare(strict_types=1);

namespace App\Http\Controllers\Databases;

use App\Actions\Databases\Configuration\RetuneDatabase;
use App\Actions\Databases\Failover\FailoverDatabase;
use App\Actions\Databases\Failover\RecoverDatabase;
use App\Actions\Databases\Lifecycle\QueueStatusRefresh;
use App\Actions\Databases\Lifecycle\RetryDatabaseSetup;
use App\Actions\Databases\Lifecycle\StopDatabaseOperation;
use App\Actions\Databases\Nodes\RebootDatabase;
use App\Actions\Databases\Upgrades\QueueUpgradeCheck;
use App\Actions\Databases\Upgrades\UpgradeDatabase;
use App\Http\Controllers\Controller;
use App\Models\Database;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DatabaseOperationController extends Controller
{
    public function failover(Database $database, FailoverDatabase $failover): RedirectResponse
    {
        $failover->handle($database);

        return back();
    }

    public function refresh(Database $database, QueueStatusRefresh $refresh): RedirectResponse
    {
        $refresh->handle($database);

        return back();
    }

    public function checkUpgrade(Database $database, QueueUpgradeCheck $check): RedirectResponse
    {
        $check->handle($database);

        return back();
    }

    public function upgrade(Database $database, UpgradeDatabase $upgrade): RedirectResponse
    {
        $upgrade->handle($database);

        return back();
    }

    public function retune(Database $database, RetuneDatabase $retune): RedirectResponse
    {
        $retune->handle($database);

        return back();
    }

    public function recover(Request $request, Database $database, RecoverDatabase $recover): RedirectResponse
    {
        $recover->handle($database, $request->boolean('others_down'));

        return back();
    }

    public function reboot(Database $database, RebootDatabase $reboot): RedirectResponse
    {
        $reboot->handle($database);

        return back();
    }

    public function stop(Database $database, StopDatabaseOperation $stop): RedirectResponse
    {
        $stop->handle($database);

        return back();
    }

    public function retrySetup(Database $database, RetryDatabaseSetup $retry): RedirectResponse
    {
        $retry->handle($database);

        return back();
    }
}
