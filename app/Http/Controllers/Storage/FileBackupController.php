<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storage;

use App\Actions\Storage\DeleteFileBackupDestination;
use App\Actions\Storage\QueueFileBackup;
use App\Actions\Storage\SaveFileBackupDestination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Storage\SaveFileBackupRequest;
use App\Http\Resources\FileBackupDestinationResource;
use App\Models\Database;
use App\Models\FileBackupDestination;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class FileBackupController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('storage/file-backups', [
            'destinations' => FileBackupDestinationResource::collection(FileBackupDestination::query()->with('database')->orderBy('name')->get())->resolve(),
            'databases' => Database::query()->whereIn('engine', ['postgres', 'mysql', 'mariadb'])->orderBy('name')->get(['id', 'name', 'engine'])->toArray(),
        ]);
    }

    public function store(SaveFileBackupRequest $request, SaveFileBackupDestination $save): RedirectResponse
    {
        $save->handle($request->toDestination());

        return back();
    }

    public function update(SaveFileBackupRequest $request, FileBackupDestination $destination, SaveFileBackupDestination $save): RedirectResponse
    {
        $save->handle($request->toDestination($destination));

        return back();
    }

    public function backup(FileBackupDestination $destination, QueueFileBackup $queue): RedirectResponse
    {
        $queue->handle($destination);

        return back();
    }

    public function destroy(FileBackupDestination $destination, DeleteFileBackupDestination $delete): RedirectResponse
    {
        $delete->handle($destination);

        return back();
    }
}
