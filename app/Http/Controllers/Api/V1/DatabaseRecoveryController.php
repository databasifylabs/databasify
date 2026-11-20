<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

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
use App\Http\Resources\Api\V1\DatabaseResource;
use App\Http\Resources\Api\V1\PointInTimeRecoveryResource;
use App\Models\Database;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DatabaseRecoveryController extends Controller
{
    public function show(Database $database): PointInTimeRecoveryResource
    {
        return new PointInTimeRecoveryResource($database->pointInTimeRecovery ?? abort(404));
    }

    public function store(EnableRecoveryRequest $request, Database $database, EnablePointInTimeRecovery $enable): JsonResponse
    {
        $enable->handle($database, $request->toSettings());

        return DatabaseResource::accepted($database);
    }

    public function update(UpdateRecoveryScheduleRequest $request, Database $database, UpdateRecoverySchedule $update): JsonResponse
    {
        $update->handle($database, $request->toSchedule());

        return DatabaseResource::accepted($database);
    }

    public function destroy(Request $request, Database $database, DisablePointInTimeRecovery $disable): JsonResponse
    {
        $disable->handle($database, $request->boolean('delete_backups'));

        return DatabaseResource::accepted($database);
    }

    public function backup(Database $database, BackUpNow $backUpNow): JsonResponse
    {
        $backUpNow->handle($database);

        return DatabaseResource::accepted($database);
    }

    public function check(Database $database, CheckRecoveryArchive $check): JsonResponse
    {
        $check->handle($database);

        return DatabaseResource::accepted($database);
    }

    public function restore(RestoreToPointInTimeRequest $request, Database $database, RestoreToPointInTime $restore): JsonResponse
    {
        return DatabaseResource::accepted($restore->handle($database, $request->toRestore()));
    }
}
