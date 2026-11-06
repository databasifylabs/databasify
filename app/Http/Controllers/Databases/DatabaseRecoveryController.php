<?php

declare(strict_types=1);

namespace App\Http\Controllers\Databases;

use App\Actions\Databases\Recovery\BackUpNow;
use App\Actions\Databases\Recovery\CheckRecoveryArchive;
use App\Actions\Databases\Recovery\DisablePointInTimeRecovery;
use App\Actions\Databases\Recovery\EnablePointInTimeRecovery;
use App\Actions\Databases\Recovery\RestoreToPointInTime;
use App\Actions\Databases\Recovery\UpdateRecoverySchedule;
use App\Http\Controllers\Controller;
use App\Http\Requests\Databases\EnableRecoveryRequest;
use App\Http\Requests\Databases\RestoreToPointInTimeRequest;
use App\Http\Requests\Databases\UpdateRecoveryScheduleRequest;
use App\Models\Database;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DatabaseRecoveryController extends Controller
{
    public function store(EnableRecoveryRequest $request, Database $database, EnablePointInTimeRecovery $enable): RedirectResponse
    {
        $enable->handle($database, $request->toSettings());

        return back();
    }

    public function update(UpdateRecoveryScheduleRequest $request, Database $database, UpdateRecoverySchedule $update): RedirectResponse
    {
        $update->handle($database, $request->toSchedule());

        return back();
    }

    public function destroy(Request $request, Database $database, DisablePointInTimeRecovery $disable): RedirectResponse
    {
        $disable->handle($database, $request->boolean('delete_backups'));

        return back();
    }

    public function backup(Database $database, BackUpNow $backUpNow): RedirectResponse
    {
        $backUpNow->handle($database);

        return back();
    }

    public function check(Database $database, CheckRecoveryArchive $check): RedirectResponse
    {
        $check->handle($database);

        return back();
    }

    public function restore(RestoreToPointInTimeRequest $request, Database $database, RestoreToPointInTime $restore): RedirectResponse
    {
        return to_route('databases.show', $restore->handle($database, $request->toRestore()));
    }
}
